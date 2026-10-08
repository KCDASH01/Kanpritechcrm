<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Stage;

class DealStatusService
{
    public function markWonIfFullyPaid(Deal $deal): bool
    {
        // A recurring contract is not complete after one billing-cycle payment.
        // Its lifecycle is managed through recurring_businesses instead.
        if ($deal->business_type === 'RECURRING') {
            return false;
        }
        if ($deal->status !== 'open') {
            return false;
        }

        $dealValue = round((float) ($deal->value ?? 0), 2);
        if ($dealValue <= 0) {
            return false;
        }

        $totalReceived = round((float) $deal->payments()->sum('amount'), 2);
        if ($totalReceived < $dealValue) {
            return false;
        }

        $updates = [
            'status'    => 'won',
            'closed_at' => now()->toDateString(),
        ];

        $targetStage = Stage::where('pipeline_id', $deal->pipeline_id)
            ->where('is_won', true)
            ->first();

        if ($targetStage) {
            $updates['stage_id'] = $targetStage->id;
        }

        $deal->update($updates);

        return true;
    }

    /**
     * Reopen a won deal when remaining amount is no longer fully paid.
     */
    public function reopenIfUnderpaid(Deal $deal): bool
    {
        if ($deal->business_type === 'RECURRING') {
            return false;
        }
        if ($deal->status !== 'won') {
            return false;
        }

        $dealValue     = round((float) ($deal->value ?? 0), 2);
        $totalReceived = round((float) $deal->payments()->sum('amount'), 2);

        if ($dealValue <= 0 || $totalReceived >= $dealValue) {
            return false;
        }

        $updates = [
            'status'    => 'open',
            'closed_at' => null,
        ];

        $openStage = Stage::where('pipeline_id', $deal->pipeline_id)
            ->where('is_won', false)
            ->where('is_lost', false)
            ->orderBy('sort_order')
            ->first();

        if ($openStage) {
            $updates['stage_id'] = $openStage->id;
        }

        $deal->update($updates);

        return true;
    }
}
