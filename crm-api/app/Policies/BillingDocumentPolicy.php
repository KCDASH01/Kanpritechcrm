<?php

namespace App\Policies;

use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\User;
use App\Services\Billing\BillingPermissionService;

class BillingDocumentPolicy
{
    public function __construct(private readonly BillingPermissionService $permissions) {}

    public function view(User $user, BillingDocument $document): bool
    {
        if ($user->organization_id !== $document->organization_id) {
            return false;
        }
        if (! $this->permissions->allows($user, $this->profile($user), 'view')) {
            return false;
        }

        return ! $user->isEmployee()
            || in_array($user->id, [$document->assigned_to, $document->created_by], true);
    }

    public function update(User $user, BillingDocument $document): bool
    {
        return $document->issued_at === null && $this->view($user, $document)
            && $this->permissions->allows($user, $this->profile($user), 'edit_draft');
    }

    public function issue(User $user, BillingDocument $document): bool
    {
        return $document->issued_at === null && $this->view($user, $document)
            && $this->permissions->allows($user, $this->profile($user), 'issue');
    }

    private function profile(User $user): ?BillingProfile
    {
        return BillingProfile::where('organization_id', $user->organization_id)->first();
    }
}
