<?php

namespace App\Services\Billing;

use App\Models\BillingAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class BillingAuditService
{
    public function record(
        int $organizationId,
        ?User $actor,
        string $event,
        string|Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): BillingAuditLog {
        return BillingAuditLog::create([
            'organization_id' => $organizationId,
            'actor_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $subject instanceof Model ? $subject::class : $subject,
            'auditable_id' => $subject instanceof Model ? $subject->getKey() : null,
            'before_values' => $before,
            'after_values' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
