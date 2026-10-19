<?php

namespace App\Services\Billing;

use App\Models\BillingProfile;
use App\Models\User;

class BillingPermissionService
{
    public const ACTIONS = [
        'view', 'create_draft', 'edit_draft', 'issue', 'void', 'send',
        'allocate_payments', 'generate_receipts', 'manage_recurring',
        'manage_settings', 'manage_sequences', 'view_audit',
    ];

    public function defaults(): array
    {
        return [
            'owner' => array_fill_keys(self::ACTIONS, true),
            'admin' => array_fill_keys(self::ACTIONS, true),
            'employee' => [
                'view' => true, 'create_draft' => true, 'edit_draft' => true,
                'issue' => false, 'void' => false, 'send' => false,
                'allocate_payments' => false, 'generate_receipts' => false,
                'manage_recurring' => false, 'manage_settings' => false,
                'manage_sequences' => false, 'view_audit' => false,
            ],
        ];
    }

    public function matrix(?BillingProfile $profile): array
    {
        $saved = $profile?->permissions ?? [];
        $defaults = $this->defaults();

        foreach ($defaults as $role => $actions) {
            foreach ($actions as $action => $allowed) {
                $defaults[$role][$action] = (bool) ($saved[$role][$action] ?? $allowed);
            }
        }

        // Owners can never lock themselves out of financial governance.
        $defaults['owner'] = array_fill_keys(self::ACTIONS, true);

        return $defaults;
    }

    public function effective(User $user, ?BillingProfile $profile): array
    {
        return $this->matrix($profile)[$user->role] ?? array_fill_keys(self::ACTIONS, false);
    }

    public function allows(User $user, ?BillingProfile $profile, string $action): bool
    {
        return (bool) ($this->effective($user, $profile)[$action] ?? false);
    }
}
