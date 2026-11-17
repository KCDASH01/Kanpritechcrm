<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LeadOwnershipService
{
    /**
     * Reassign an already locked collection of leads and preserve an auditable
     * ownership history. Callers are responsible for the surrounding transaction.
     */
    public function reassignLocked(
        Collection $leads,
        ?User $newOwner,
        User $actor,
        string $source,
        ?string $reason = null,
        ?int $relatedEmployeeId = null,
    ): int {
        $changed = 0;
        $newOwnerId = $newOwner?->id;
        $newDepartmentId = $newOwner?->departments()
            ->where('departments.organization_id', $actor->organization_id)
            ->orderBy('departments.id')
            ->value('departments.id');

        foreach ($leads as $lead) {
            $previousOwnerId = $lead->assigned_to;
            if ($previousOwnerId === $newOwnerId) {
                continue;
            }

            $lead->forceFill([
                'assigned_to' => $newOwnerId,
                'department_id' => $newDepartmentId,
            ])->save();

            // Keep pending work actionable without rewriting historical deals or
            // financial attribution.
            Activity::query()
                ->where('subject_id', $lead->id)
                ->whereIn('subject_type', ['lead', Lead::class])
                ->where('is_done', false)
                ->update(['assigned_to' => $newOwnerId]);

            $previousOwnerName = $previousOwnerId
                ? User::withTrashed()->whereKey($previousOwnerId)->value('name')
                : null;

            LeadTimeline::log(
                $lead,
                'assigned',
                $newOwner
                    ? "Ownership transferred to {$newOwner->name} by {$actor->name}"
                    : "Ownership set to Unassigned by {$actor->name}",
                $actor->id,
                [
                    'previous_owner_id' => $previousOwnerId,
                    'previous_owner_name' => $previousOwnerName,
                    'new_owner_id' => $newOwnerId,
                    'new_owner_name' => $newOwner?->name,
                    'actor_id' => $actor->id,
                    'source' => $source,
                    'reason' => $reason,
                    'related_employee_id' => $relatedEmployeeId,
                ],
            );

            if ($newOwnerId && $newOwnerId !== $actor->id) {
                Notification::notify(
                    $lead->organization_id,
                    $newOwnerId,
                    'lead_assigned',
                    'Lead Assigned to You',
                    "{$actor->name} assigned \"{$lead->full_name}\" to you",
                    ['lead_id' => $lead->id, 'source' => $source],
                    "/leads/{$lead->id}",
                );
            }

            $changed++;
        }

        return $changed;
    }
}
