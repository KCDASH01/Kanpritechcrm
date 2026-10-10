<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Services\LeadIntegrations\IntegrationLeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIntegrationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $eventId) {}

    public function handle(IntegrationLeadService $service): void
    {
        $event = IntegrationEvent::query()->findOrFail($this->eventId);
        if (in_array($event->status, ['imported', 'linked', 'ignored'], true)) {
            return;
        }

        $service->process($event, $event->extracted_data ?: $event->payload ?: []);
    }

    public function failed(\Throwable $exception): void
    {
        IntegrationEvent::query()->whereKey($this->eventId)->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'processed_at' => now(),
        ]);
    }
}
