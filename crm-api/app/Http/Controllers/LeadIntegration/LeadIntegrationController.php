<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Jobs\RetrieveMetaLead;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\IntegrationCampaign;
use App\Models\IntegrationCampaignRecipient;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\IntegrationReviewItem;
use App\Models\Lead;
use App\Models\LeadAttribution;
use App\Models\LeadIntegrationSetting;
use App\Models\LeadRoutingRule;
use App\Services\LeadIntegrations\IntegrationLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class LeadIntegrationController extends Controller
{
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
                ? IntegrationConnection::query()->where('organization_id', $orgId)->latest()->get()
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
                'whatsapp' => false,
            ],
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'in:meta,whatsapp,google,microsoft,email'],
            'status' => ['nullable', 'in:pending,imported,linked,review,ignored,failed'],
        ]);
        $events = $this->visibleEvents($request)
            ->when($data['provider'] ?? null, fn ($query, $value) => $query->where('provider', $value))
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->with(['lead:id,first_name,last_name', 'assignedTo:id,name', 'campaign:id,name'])
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

        return response()->json(['message' => 'Integration disconnected. Historical CRM data was preserved.']);
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
        $created = 0;
        do {
            $response = Http::withToken($connection->access_token)->get($url, [
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
            'source_type' => [$sometimes, 'in:campaign,ad,form,alias,mailbox,authorized_sender,provider,default'],
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
}
