<?php

namespace App\Services;

use App\Models\BillingCatalogueItem;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadCreationService
{
    public function __construct(private LeadAuthorizationService $authorization, private PlanEntitlementService $plans) {}

    public function create(User $user, array $payload, ?string $importDate = null, ?int $importId = null, bool $organizationLocked = false): Lead
    {
        abort_unless($this->authorization->canCreate($user), 403, 'You are not allowed to create leads.');

        return DB::transaction(function () use ($user, $payload, $importDate, $importId, $organizationLocked) {
            if (! $organizationLocked) {
                $user->organization()->lockForUpdate()->firstOrFail();
                $this->assertCapacity($user);
            }

            $attributes = $this->authorization->attributesForCreate($user, $this->resolveCatalogue($user, $payload));
            if (($attributes['client_type'] ?? null) === 'EXISTING') {
                $client = $this->visibleClients($user)->findOrFail($attributes['client_id']);
                $attributes = array_merge($attributes, $this->clientSnapshot($client));
            }
            if ($importId !== null) {
                $attributes['assigned_to'] = $user->id;
            }
            if ($importId === null || ! array_key_exists('department_id', $attributes)) {
                $attributes['department_id'] ??= $this->departmentForAssignee($attributes['assigned_to'] ?? null, $user->organization_id);
            }
            $lead = Lead::create(array_merge($attributes, [
                'organization_id' => $user->organization_id,
                'created_by' => $user->id,
                'lead_date' => $importDate ?? now()->toDateString(),
            ]));
            if ($importId === null) {
                $lead->refresh();
            }

            $meta = ['source' => $lead->source, 'status' => $lead->status];
            if ($importId !== null) {
                $meta['lead_import_id'] = $importId;
            }
            LeadTimeline::log($lead, 'created', "Lead created by {$user->name}", $user->id, $meta);

            return $lead;
        });
    }

    public function remainingCapacity(User $user): ?int
    {
        $limit = $this->plans->limits($user->organization)['leads'];

        return $limit === null ? null : max(0, $limit - Lead::where('organization_id', $user->organization_id)->count());
    }

    private function assertCapacity(User $user): void
    {
        if ($this->remainingCapacity($user) === 0) {
            $limit = $this->plans->limits($user->organization)['leads'];
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => "You have reached the {$limit}-lead limit for your current plan.",
                'code' => 'LIMIT_REACHED',
            ], 403));
        }
    }

    public function resolveCatalogue(User $user, array $attributes): array
    {
        $id = $attributes['catalogue_item_id'] ?? null;
        unset($attributes['catalogue_item_id']);
        if ($id === null) {
            return $attributes;
        }

        $item = BillingCatalogueItem::where('organization_id', $user->organization_id)
            ->where('is_active', true)->find($id);
        if (! $item) {
            throw ValidationException::withMessages(['catalogue_item_id' => 'The selected product or service is no longer available.']);
        }
        $attributes['types'] = $item->name;

        return $attributes;
    }

    public function visibleClients(User $user): Builder
    {
        return Client::where('organization_id', $user->organization_id)
            ->when($user->isEmployee(), fn ($query) => $query->where(fn ($visible) => $visible
                ->where('assigned_to', $user->id)
                ->orWhereHas('leads', fn ($lead) => $lead->where('assigned_to', $user->id))
                ->orWhereHas('deals', fn ($deal) => $deal->where('assigned_to', $user->id))));
    }

    public function clientSnapshot(Client|Lead $source): array
    {
        return $source->only([
            'first_name', 'last_name', 'company', 'email', 'phone', 'job_title', 'website', 'city', 'state', 'country',
        ]);
    }

    public function departmentForAssignee(?int $userId, int $organizationId): ?int
    {
        return $userId ? User::where('organization_id', $organizationId)->find($userId)?->departments()->value('departments.id') : null;
    }
}
