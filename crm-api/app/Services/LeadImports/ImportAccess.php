<?php

namespace App\Services\LeadImports;

use App\Models\LeadImport;
use App\Models\User;
use App\Services\LeadAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

class ImportAccess
{
    public function __construct(private LeadAuthorizationService $leads) {}

    public function canImport(User $user): bool
    {
        $organization = $user->organization;
        $subscription = $organization?->activeSubscription();

        return $user->is_active && $organization?->is_active && $this->leads->canCreate($user)
            && in_array($subscription?->plan, ['business', 'enterprise'], true)
            && ! $subscription->isExpired() && ! $subscription->isReadOnly();
    }

    public function visible(User $user): Builder
    {
        return LeadImport::where('organization_id', $user->organization_id)
            ->when(! $this->leads->canManageAny($user), fn ($query) => $query->where('uploaded_by', $user->id));
    }

    public function uploader(LeadImport $import, bool $lock = false): ?User
    {
        $user = User::where('organization_id', $import->organization_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($import->uploaded_by);

        return $user && $this->canImport($user) ? $user : null;
    }
}
