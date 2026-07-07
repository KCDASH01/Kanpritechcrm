<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;

/**
 * Centralized lead access rules for owners, admins, and employees.
 */
class LeadAuthorizationService
{
    public function canCreate(User $user): bool
    {
        return in_array($user->role, ['owner', 'admin', 'employee'], true);
    }

    public function canManageAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function canUpdate(User $user, Lead $lead): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role === 'employee' && $lead->assigned_to === $user->id;
    }

    public function canDelete(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Force employee ownership fields on create — never trust the client.
     */
    public function attributesForCreate(User $user, array $validated): array
    {
        if ($user->role !== 'employee') {
            return $validated;
        }

        return array_merge($validated, [
            'assigned_to' => $user->id,
            'created_by'  => $user->id,
        ]);
    }

    /**
     * Prevent employees from changing assignment or creator on update.
     */
    public function attributesForUpdate(User $user, array $validated): array
    {
        if ($user->role !== 'employee') {
            return $validated;
        }

        unset($validated['created_by']);
        $validated['assigned_to'] = $user->id;

        return $validated;
    }
}
