<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIntegrationEvent;
use App\Jobs\RetrieveMetaLead;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\IntegrationAutomation;
use App\Models\IntegrationCampaign;
use App\Models\IntegrationCampaignRecipient;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionAudit;
use App\Models\IntegrationEvent;
use App\Models\IntegrationReviewItem;
use App\Models\Lead;
use App\Models\LeadAttribution;
use App\Models\LeadIntegrationSetting;
use App\Models\LeadRoutingRule;
use App\Services\LeadIntegrations\IntegrationLeadService;
use App\Services\LeadIntegrations\LeadRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadIntegrationController extends Controller
{
    public function providers(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['data' => [
            $this->provider('meta', 'Facebook Lead Ads', 'oauth', ['instant_forms', 'history_import'],
                (bool) (config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.redirect_uri')),
                'Meta business verification, App Review and Page Lead Access may be required.'),
            $this->provider('whatsapp', 'WhatsApp Business', 'embedded_signup', ['incoming_messages', 'click_to_whatsapp'],
                (bool) (config('services.meta.app_id') && config('services.meta.whatsapp_config_id')),
                'Meta Tech Provider/Embedded Signup eligibility, business verification and an eligible number are required.'),
            $this->provider('google', 'Gmail / Google Workspace', 'oauth', ['mailbox_sync', 'reply_attribution'],
                (bool) (config('services.google.client_id') && config('services.google.client_secret') && config('services.google.redirect_uri')),
                'Google OAuth consent and Gmail API access are required.'),
            $this->provider('microsoft', 'Microsoft 365 / Outlook', 'oauth', ['mailbox_sync', 'reply_attribution'],
                (bool) (config('services.microsoft.client_id') && config('services.microsoft.client_secret') && config('services.microsoft.redirect_uri')),
                'Microsoft consent for User.Read, Mail.Read and offline_access is required.'),
            $this->provider('email', 'Other Email', 'advanced', ['approved_forwarding'], true,
                'Use an approved forwarding endpoint. Passwords are never collected in the browser.'),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'provider' => ['nullable', 'in:meta,whatsapp,google,microsoft,email'], 'campaign_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'], 'country' => ['nullable', 'string', 'max:191'],
            'lead_status' => ['nullable', 'string', 'max:64'], 'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        if (! $request->user()->isAdmin()) {
            unset($filters['assigned_to']);
        }
        $orgId = $request->user()->organization_id;
        $events = $this->visibleEvents($request)
            ->when($filters['provider'] ?? null, fn ($query, $value) => $query->where('provider', $value))
            ->when($filters['campaign_id'] ?? null, fn ($query, $value) => $query->where('campaign_id', $value))
            ->when($filters['assigned_to'] ?? null, fn ($query, $value) => $query->where('assigned_to', $value))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->when(($filters['country'] ?? null) || ($filters['lead_status'] ?? null), function ($query) use ($filters): void {
                $query->whereHas('lead', fn ($lead) => $lead
                    ->when($filters['country'] ?? null, fn ($inner, $value) => $inner->where('country', $value))
                    ->when($filters['lead_status'] ?? null, fn ($inner, $value) => $inner->where('status', $value)));
            });
        $eventIds = (clone $events)->pluck('id');
        $attributedLeadIds = LeadAttribution::query()->where('organization_id', $orgId)
            ->whereIn('integration_event_id', $eventIds)->distinct()->pluck('lead_id');
        $dealQuery = Deal::query()->where('organization_id', $orgId)->whereIn('lead_id', $attributedLeadIds);
        $wonDealIds = (clone $dealQuery)->where('status', 'won')->pluck('id');
        $capturedCount = $attributedLeadIds->count();
        $revenue = DealPayment::query()->where('deal_payments.organization_id', $orgId)->whereIn('deal_id', $wonDealIds)
            ->selectRaw('deals.currency as currency, SUM(deal_payments.amount) as total')
            ->join('deals', 'deals.id', '=', 'deal_payments.deal_id')
            ->groupBy('deals.currency')->pluck('total', 'currency');

        return response()->json(['data' => [
            'summary' => [
                'total_leads' => $capturedCount,
                'qualified_leads' => Lead::query()->where('organization_id', $orgId)->whereIn('id', $attributedLeadIds)->where('status', 'converted')->count(),
                'meta_leads' => (clone $events)->where('provider', 'meta')->whereIn('status', ['imported', 'linked'])->count(),
                'email_leads' => (clone $events)->whereIn('provider', ['google', 'microsoft', 'email'])->whereIn('status', ['imported', 'linked'])->count(),
                'campaign_replies' => (clone $events)->where('event_type', 'email_reply')->count(),
                'interested_replies' => (clone $events)->where('event_type', 'email_reply')->whereIn('classification', ['interested', 'meeting_requested', 'requesting_information'])->count(),
                'review_required' => IntegrationReviewItem::query()->where('organization_id', $orgId)->where('status', 'pending')
                    ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('assigned_to', $request->user()->id))->count(),
                'assigned' => (clone $events)->whereNotNull('assigned_to')->whereIn('status', ['imported', 'linked'])->count(),
                'deals_created' => (clone $dealQuery)->count(),
                'deals_won' => $wonDealIds->count(),
                'conversion_rate' => $capturedCount > 0 ? round($wonDealIds->count() / $capturedCount * 100, 2) : 0,
                'revenue_collected' => $revenue,
            ],
            'source_performance' => (clone $events)->whereIn('status', ['imported', 'linked'])
                ->selectRaw('provider, COUNT(*) as leads')->groupBy('provider')->get(),
            'connections' => $request->user()->isAdmin()
                ? IntegrationConnection::query()->where('organization_id', $orgId)->with(['assets' => fn ($query) => $query->where('is_selected', true)])
                    ->withCount(['assets as selected_assets_count' => fn ($query) => $query->where('is_selected', true)])->latest()->get()
                : [],
            'campaigns' => IntegrationCampaign::query()->where('organization_id', $orgId)->with('assignedTo:id,name')->latest()->get(),
            'routing_rules' => $request->user()->isAdmin()
                ? LeadRoutingRule::query()->where('organization_id', $orgId)->with(['assignedTo:id,name', 'backupUser:id,name', 'department:id,name'])->orderBy('priority')->get()
                : [],
            'settings' => $request->user()->isAdmin()
                ? LeadIntegrationSetting::query()->firstOrCreate(['organization_id' => $orgId])
                : null,
            'provider_readiness' => [
                'meta' => (bool) (config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.graph_version')),
                'google' => (bool) (config('services.google.client_id') && config('services.google.client_secret')),
                'microsoft' => (bool) (config('services.microsoft.client_id') && config('services.microsoft.client_secret')),
                'whatsapp' => (bool) (config('services.meta.app_id') && config('services.meta.app_secret')
                    && config('services.meta.graph_version') && config('services.meta.whatsapp_config_id')
                    && config('services.meta.whatsapp_redirect_uri')),
            ],
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        if ($request->filled('status')) {
            $request->merge(['status' => mb_strtolower($request->string('status')->toString())]);
        }
        $data = $request->validate([
            'provider' => ['nullable', 'in:meta,whatsapp,google,microsoft,email'],
            'status' => ['nullable', 'in:pending,imported,linked,review,ignored,failed'],
            'connection_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $events = $this->visibleEvents($request)
            ->when($data['provider'] ?? null, fn ($query, $value) => $query->where('provider', $value))
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($data['connection_id'] ?? null, fn ($query, $value) => $query->where('connection_id', $value))
            ->when($data['assigned_to'] ?? null, fn ($query, $value) => $query->where('assigned_to', $value))
            ->when($data['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($data['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->with(['connection:id,name,account_email', 'lead:id,first_name,last_name', 'assignedTo:id,name', 'campaign:id,name'])
            ->latest()->paginate(25);

        return response()->json(['data' => $events->items(), 'meta' => [
            'current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total(),
        ]]);
    }

    public function reviewQueue(Request $request): JsonResponse
    {
        $this->admin($request);
        $items = IntegrationReviewItem::query()->where('organization_id', $request->user()->organization_id)
            ->with(['event:id,provider,event_type,status', 'assignedTo:id,name', 'linkedLead:id,first_name,last_name'])
            ->latest()->paginate(25);

        return response()->json(['data' => $items->items(), 'meta' => ['total' => $items->total()]]);
    }

    public function retryEvent(Request $request, IntegrationEvent $event): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $event);
        abort_unless($event->status === 'failed', 422, 'Only failed events can be retried.');
        $event->update(['status' => 'pending', 'error_message' => null, 'processed_at' => null]);
        $event->provider === 'meta' ? RetrieveMetaLead::dispatch($event->id) : ProcessIntegrationEvent::dispatch($event->id);

        return response()->json(['data' => $event->fresh(), 'message' => 'The failed record was queued for retry.']);
    }

    public function exportHistory(Request $request): StreamedResponse
    {
        $this->admin($request);
        $rows = $this->visibleEvents($request)->with(['connection:id,name,account_email', 'assignedTo:id,name', 'campaign:id,name'])
            ->latest()->limit(10000)->get();

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Event ID', 'Provider', 'Account', 'Campaign', 'Status', 'Assigned To', 'Received At', 'Processed At', 'Error']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row->id, $row->provider, $row->connection?->account_email ?: $row->connection?->name,
                    $row->campaign?->name, $row->status, $row->assignedTo?->name,
                    $row->created_at?->toISOString(), $row->processed_at?->toISOString(), $row->error_message,
                ]);
            }
            fclose($output);
        }, 'lead-integration-history-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeConnection(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'provider' => ['required', 'in:email'], 'name' => ['required', 'string', 'max:191'],
            'account_email' => ['required', 'email'], 'authorized_senders' => ['nullable', 'array'],
            'authorized_senders.*' => ['email'], 'receiving_aliases' => ['nullable', 'array'],
            'receiving_aliases.*' => ['email'],
        ]);
        $connection = IntegrationConnection::create([
            'organization_id' => $request->user()->organization_id, 'connected_by' => $request->user()->id,
            'provider' => 'email', 'name' => $data['name'], 'account_email' => mb_strtolower($data['account_email']),
            'external_account_id' => mb_strtolower($data['account_email']), 'status' => 'active',
            'settings' => [
                'authorized_senders' => array_map('mb_strtolower', $data['authorized_senders'] ?? []),
                'receiving_aliases' => array_map('mb_strtolower', $data['receiving_aliases'] ?? []),
                'inbound_secret' => bin2hex(random_bytes(32)),
            ],
        ]);

        return response()->json(['data' => $connection, 'message' => 'Approved inbound email connection created.'], 201);
    }

    public function disconnect(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        $connection->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null]);
        $this->audit($request, $connection, 'disconnect', 'success');

        return response()->json(['message' => 'Integration disconnected. Historical CRM data was preserved.']);
    }

    public function pause(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        abort_if($connection->status === 'disconnected', 422, 'Reconnect this account before pausing it.');
        $connection->update(['status' => 'paused', 'paused_at' => now()]);
        $this->audit($request, $connection, 'pause', 'success');

        return response()->json(['data' => $connection->fresh(), 'message' => 'Synchronization paused. Historical CRM data is unchanged.']);
    }

    public function resume(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        abort_unless($connection->status === 'paused', 422, 'Only a paused connection can be resumed.');
        $connection->update(['status' => 'active', 'paused_at' => null, 'last_error' => null]);
        $this->audit($request, $connection, 'resume', 'success');

        return response()->json(['data' => $connection->fresh(), 'message' => 'Synchronization resumed.']);
    }

    public function testConnection(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        $healthy = $connection->status === 'active' && ($connection->provider === 'email' || filled($connection->access_token));
        if ($healthy && $connection->token_expires_at?->isPast() && blank($connection->refresh_token)) {
            $healthy = false;
        }
        $message = $healthy ? 'Connection credentials and local configuration are available.' : 'Connection needs authorization or reconnection.';
        if ($healthy && $connection->provider !== 'email') {
            try {
                $probe = match ($connection->provider) {
                    'meta', 'whatsapp' => Http::withToken($connection->access_token)->get('https://graph.facebook.com/'.config('services.meta.graph_version').'/me', ['fields' => 'id']),
                    'google' => Http::withToken($connection->access_token)->get('https://gmail.googleapis.com/gmail/v1/users/me/profile'),
                    'microsoft' => Http::withToken($connection->access_token)->get('https://graph.microsoft.com/v1.0/me?$select=id'),
                };
                $healthy = $probe->successful();
                $message = $healthy ? 'Provider authorization is healthy.' : 'The provider rejected the authorization. Reconnect or refresh permissions.';
            } catch (\Throwable) {
                $healthy = false;
                $message = 'The provider health check could not be completed. Check network access and retry.';
            }
        }
        $connection->update([
            'health_checked_at' => now(), 'webhook_status' => $healthy ? 'healthy' : 'attention',
            'last_error' => $healthy ? null : $message,
        ]);
        $this->audit($request, $connection, 'test', $healthy ? 'success' : 'failed', ['message' => $message]);

        return response()->json(['data' => ['healthy' => $healthy, 'message' => $message]], $healthy ? 200 : 422);
    }

    public function assets(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        $data = $request->validate(['type' => ['nullable', 'string', 'max:48'], 'search' => ['nullable', 'string', 'max:191']]);
        $assets = $connection->assets()->when($data['type'] ?? null, fn ($query, $value) => $query->where('asset_type', $value))
            ->when($data['search'] ?? null, fn ($query, $value) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$value}%")->orWhere('external_id', 'like', "%{$value}%")))
            ->orderBy('asset_type')->orderBy('name')->get();

        return response()->json(['data' => $assets]);
    }

    public function selectAssets(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        $data = $request->validate(['asset_ids' => ['required', 'array'], 'asset_ids.*' => ['integer']]);
        $ownedIds = $connection->assets()->whereIn('id', $data['asset_ids'])->pluck('id');
        abort_if($ownedIds->count() !== count(array_unique($data['asset_ids'])), 422, 'One or more assets are not available to this connection.');
        $connection->assets()->update(['is_selected' => false]);
        $connection->assets()->whereIn('id', $ownedIds)->update(['is_selected' => true, 'last_verified_at' => now()]);
        $webhookHealthy = ! $ownedIds->isEmpty();
        if ($connection->provider === 'meta' && $webhookHealthy) {
            $version = config('services.meta.graph_version');
            foreach ($connection->assets()->whereIn('id', $ownedIds)->where('asset_type', 'page')->get() as $page) {
                $response = Http::withToken($page->access_token ?: $connection->access_token)
                    ->post("https://graph.facebook.com/{$version}/{$page->external_id}/subscribed_apps", ['subscribed_fields' => 'leadgen']);
                $webhookHealthy = $webhookHealthy && $response->successful();
            }
        }
        $settings = $connection->settings ?? [];
        if ($connection->provider === 'whatsapp' && $connection->assets()->whereIn('id', $ownedIds)->where('asset_type', 'phone_number')->exists()) {
            $settings['eligibility_verified'] = true;
        }
        $connection->update([
            'status' => $ownedIds->isEmpty() ? 'setup_required' : 'active',
            'webhook_status' => $ownedIds->isEmpty() ? 'pending' : ($webhookHealthy ? 'healthy' : 'attention'),
            'settings' => $settings,
            'last_error' => $webhookHealthy ? null : 'One or more selected assets could not be subscribed. Refresh permissions and retry.',
        ]);
        $this->audit($request, $connection, 'select_assets', 'success', ['asset_count' => $ownedIds->count()]);

        return response()->json(['data' => $connection->fresh()->load('assets'), 'message' => 'Connected assets updated.']);
    }

    public function routingPreview(Request $request, LeadRoutingService $routing): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:32'], 'campaign_id' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'], 'service' => ['nullable', 'string', 'max:191'],
        ]);

        return response()->json(['data' => $routing->preview($request->user()->organization_id, $data)]);
    }

    public function automations(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['data' => IntegrationAutomation::query()->where('organization_id', $request->user()->organization_id)->latest()->get()]);
    }

    public function storeAutomation(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'], 'template_key' => ['nullable', 'string', 'max:64'],
            'trigger' => ['required', 'in:new_lead,form_submitted,inbound_email,interested_reply,whatsapp_enquiry,lead_assigned,lead_status_changed,deal_converted,follow_up_overdue'],
            'conditions' => ['nullable', 'array'], 'actions' => ['required', 'array', 'min:1'], 'is_active' => ['boolean'],
        ]);
        $automation = IntegrationAutomation::create($data + ['organization_id' => $request->user()->organization_id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $automation], 201);
    }

    public function updateAutomation(Request $request, IntegrationAutomation $automation): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $automation);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $automation->update($data);

        return response()->json(['data' => $automation->fresh()]);
    }

    public function testAutomation(Request $request, IntegrationAutomation $automation): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $automation);
        $sample = $request->validate(['sample' => ['nullable', 'array']]);

        return response()->json(['data' => [
            'matched' => true, 'dry_run' => true, 'trigger' => $automation->trigger,
            'actions' => $automation->actions, 'sample' => $sample['sample'] ?? [],
            'message' => 'Test run completed without changing CRM records or sending messages.',
        ]]);
    }

    public function syncMetaHistory(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $connection);
        abort_unless($connection->provider === 'meta' && $connection->status === 'active' && $connection->access_token, 422, 'An active Meta Page connection is required.');
        $data = $request->validate([
            'form_id' => ['required', 'string', 'max:191'], 'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);
        $version = config('services.meta.graph_version');
        abort_unless($version, 503, 'META_GRAPH_VERSION is not configured.');
        $url = "https://graph.facebook.com/{$version}/{$data['form_id']}/leads";
        $form = $connection->assets()->where('asset_type', 'form')->where('external_id', $data['form_id'])->first();
        abort_unless($form, 422, 'Select a form discovered through this authorized Meta connection.');
        $pageToken = $form->parent_external_id
            ? $connection->assets()->where('asset_type', 'page')->where('external_id', $form->parent_external_id)->first()?->access_token
            : null;
        $created = 0;
        do {
            $response = Http::withToken($pageToken ?: $connection->access_token)->get($url, [
                'fields' => 'id,created_time', 'since' => strtotime($data['date_from']),
                'until' => strtotime($data['date_to'].' 23:59:59'), 'limit' => 100,
            ])->throw()->json();
            foreach ($response['data'] ?? [] as $lead) {
                $event = IntegrationEvent::query()->firstOrCreate(
                    ['provider' => 'meta', 'external_event_id' => 'leadgen:'.(string) $lead['id']],
                    [
                        'organization_id' => $connection->organization_id, 'connection_id' => $connection->id,
                        'event_type' => 'instant_form_lead', 'payload' => ['leadgen_id' => $lead['id'], 'form_id' => $data['form_id']],
                    ]
                );
                if ($event->wasRecentlyCreated) {
                    RetrieveMetaLead::dispatch($event->id);
                    $created++;
                }
            }
            $url = data_get($response, 'paging.next');
        } while ($url);

        return response()->json(['data' => ['queued' => $created], 'message' => 'Accessible historical leads were queued; existing provider IDs were skipped.']);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->campaignData($request);
        $campaign = IntegrationCampaign::create(array_merge($data, ['organization_id' => $request->user()->organization_id]));

        return response()->json(['data' => $campaign->load('assignedTo:id,name')], 201);
    }

    public function updateCampaign(Request $request, IntegrationCampaign $campaign): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $campaign);
        $campaign->update($this->campaignData($request, true));

        return response()->json(['data' => $campaign->fresh()->load('assignedTo:id,name')]);
    }

    public function destroyCampaign(Request $request, IntegrationCampaign $campaign): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $campaign);
        $campaign->delete();

        return response()->json(status: 204);
    }

    public function storeRecipients(Request $request, IntegrationCampaign $campaign): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $campaign);
        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:1000'],
            'recipients.*.email' => ['required', 'email'], 'recipients.*.message_id' => ['nullable', 'string', 'max:998'],
            'recipients.*.thread_id' => ['nullable', 'string', 'max:500'], 'recipients.*.lead_id' => ['nullable', 'integer'],
        ]);
        $imported = 0;
        foreach ($data['recipients'] as $row) {
            abort_if(empty($row['message_id']) && empty($row['thread_id']), 422, 'Each recipient requires an outbound Message-ID or provider thread ID.');
            $leadId = ! empty($row['lead_id'])
                ? Lead::query()->where('organization_id', $request->user()->organization_id)->whereKey($row['lead_id'])->value('id')
                : null;
            abort_if(! empty($row['lead_id']) && ! $leadId, 422, 'A recipient lead does not belong to this organization.');
            IntegrationCampaignRecipient::query()->updateOrCreate(
                ['campaign_id' => $campaign->id, 'email' => mb_strtolower($row['email'])],
                [
                    'organization_id' => $request->user()->organization_id, 'lead_id' => $leadId,
                    'message_id' => $row['message_id'] ?? null, 'thread_id' => $row['thread_id'] ?? null,
                    'status' => 'sent',
                ]
            );
            $imported++;
        }

        return response()->json(['data' => ['imported' => $imported], 'message' => 'Campaign recipients registered.']);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->ruleData($request);
        $rule = LeadRoutingRule::create(array_merge($data, ['organization_id' => $request->user()->organization_id]));

        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, LeadRoutingRule $rule): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $rule);
        $rule->update($this->ruleData($request, true));

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroyRule(Request $request, LeadRoutingRule $rule): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $rule);
        $rule->delete();

        return response()->json(status: 204);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'default_owner_id' => ['nullable', 'integer', $this->employeeRule($request)],
            'meta_enabled' => ['boolean'], 'whatsapp_enabled' => ['boolean'], 'email_enabled' => ['boolean'],
            'auto_import_enabled' => ['boolean'], 'review_unmatched' => ['boolean'],
            'raw_content_retention_days' => ['integer', 'min:1', 'max:365'], 'field_mappings' => ['nullable', 'array'],
        ]);
        if (($data['whatsapp_enabled'] ?? false) && ! IntegrationConnection::query()
            ->where('organization_id', $request->user()->organization_id)->where('provider', 'whatsapp')
            ->where('status', 'active')->where('settings->eligibility_verified', true)->exists()) {
            return response()->json(['message' => 'WhatsApp cannot be enabled until official API and coexistence eligibility are verified.'], 422);
        }
        $settings = LeadIntegrationSetting::query()->updateOrCreate(['organization_id' => $request->user()->organization_id], $data);

        return response()->json(['data' => $settings]);
    }

    public function resolveReview(Request $request, IntegrationReviewItem $review, IntegrationLeadService $service): JsonResponse
    {
        $this->admin($request);
        $this->owned($request, $review);
        $data = $request->validate([
            'action' => ['required', 'in:approve,link,reject,retry'], 'lead_id' => ['required_if:action,link', 'nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer', $this->employeeRule($request)], 'fields' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_if($review->status !== 'pending', 409, 'This review item has already been resolved.');
        if ($data['action'] === 'link') {
            $lead = Lead::query()->where('organization_id', $request->user()->organization_id)->findOrFail($data['lead_id']);
            $event = $review->event;
            abort_unless($event, 409, 'The source event is unavailable.');
            $event->update(['status' => 'pending', 'error_message' => null]);
            $service->process($event->fresh(), array_merge($review->extracted_data ?? [], ['lead_id' => $lead->id]));
            $review->update(['status' => 'linked', 'linked_lead_id' => $lead->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'resolution_notes' => $data['notes'] ?? null]);
        } elseif ($data['action'] === 'reject') {
            $review->event?->update(['status' => 'ignored', 'processed_at' => now()]);
            $review->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'resolution_notes' => $data['notes'] ?? null]);
        } else {
            $event = $review->event;
            abort_unless($event, 409, 'The source event is unavailable.');
            $fields = array_merge($review->extracted_data ?? [], $data['fields'] ?? [], ['_review_assigned_to' => $data['assigned_to'] ?? null]);
            $event->update(['status' => 'pending', 'error_message' => null, 'extracted_data' => $fields]);
            $review->update(['status' => 'resolved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'resolution_notes' => $data['notes'] ?? null]);
            $data['action'] === 'retry' && $event->provider === 'meta'
                ? RetrieveMetaLead::dispatch($event->id)
                : $service->process($event->fresh(), $fields);
        }

        return response()->json(['data' => $review->fresh()]);
    }

    private function visibleEvents(Request $request)
    {
        return IntegrationEvent::query()->where('organization_id', $request->user()->organization_id)
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->where('assigned_to', $request->user()->id));
    }

    private function campaignData(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'connection_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer', $this->employeeRule($request)],
            'platform' => [$sometimes, 'in:meta,whatsapp,google,microsoft,email'], 'external_id' => ['nullable', 'string', 'max:191'],
            'name' => [$sometimes, 'string', 'max:191'], 'campaign_type' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:active,paused,completed'], 'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'target_market' => ['nullable', 'string', 'max:191'],
            'service' => ['nullable', 'string', 'max:191'], 'metadata' => ['nullable', 'array'],
        ]);
    }

    private function ruleData(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:191'],
            'source_type' => [$sometimes, 'in:campaign,ad,form,alias,mailbox,authorized_sender,provider,country,service,default'],
            'source_key' => ['nullable', 'string', 'max:191'], 'strategy' => [$sometimes, 'in:fixed,round_robin'],
            'assigned_to' => ['nullable', 'integer', $this->employeeRule($request)],
            'backup_user_id' => ['nullable', 'integer', $this->employeeRule($request)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('organization_id', $request->user()->organization_id))],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10000'], 'is_active' => ['boolean'], 'settings' => ['nullable', 'array'],
        ]);
    }

    private function employeeRule(Request $request)
    {
        return Rule::exists('users', 'id')->where(fn ($q) => $q->where('organization_id', $request->user()->organization_id)->where('is_active', true));
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function owned(Request $request, object $model): void
    {
        abort_unless($model->organization_id === $request->user()->organization_id, 404);
    }

    private function provider(string $id, string $name, string $method, array $capabilities, bool $available, string $requirement): array
    {
        return compact('id', 'name', 'method', 'capabilities', 'available', 'requirement');
    }

    private function audit(Request $request, IntegrationConnection $connection, string $action, string $status, array $details = []): void
    {
        IntegrationConnectionAudit::create([
            'organization_id' => $connection->organization_id, 'connection_id' => $connection->id,
            'actor_id' => $request->user()->id, 'action' => $action, 'status' => $status, 'details' => $details,
        ]);
    }
}
