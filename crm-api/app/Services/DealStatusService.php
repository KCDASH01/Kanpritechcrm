<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Stage;

class DealStatusService
{
    public function markWonIfFullyPaid(Deal $deal): bool
    {
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
}
