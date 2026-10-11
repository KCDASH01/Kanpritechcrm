<?php

namespace App\Services\LeadIntegrations;

use App\Models\Activity;
use App\Models\IntegrationAutomation;
use App\Models\IntegrationAutomationRun;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Throwable;

class IntegrationAutomationService
{
    public function execute(IntegrationEvent $event): void
    {
        if (! $event->organization_id || ! $event->lead_id || ! in_array($event->status, ['imported', 'linked'], true)) {
            return;
        }

        $triggers = $this->triggers($event);
        $automations = IntegrationAutomation::query()
            ->where('organization_id', $event->organization_id)
            ->where('is_active', true)
            ->whereIn('trigger', $triggers)
            ->get();

        foreach ($automations as $automation) {
            $this->run($automation, $event);
        }
    }

    private function run(IntegrationAutomation $automation, IntegrationEvent $event): void
    {
        $key = 'integration-event:'.$event->id;
        $run = IntegrationAutomationRun::query()->firstOrCreate(
            ['automation_id' => $automation->id, 'idempotency_key' => $key],
            [
                'organization_id' => $automation->organization_id, 'integration_event_id' => $event->id,
                'status' => 'queued', 'attempts' => 0,
            ]
        );
        if (! $run->wasRecentlyCreated || $run->status === 'completed') {
            return;
        }

        try {
            DB::transaction(function () use ($automation, $event, $run): void {
                $locked = IntegrationAutomationRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($locked->status === 'completed') {
                    return;
                }
                $locked->update(['status' => 'processing', 'attempts' => $locked->attempts + 1, 'started_at' => now()]);
                $lead = Lead::query()->where('organization_id', $automation->organization_id)->findOrFail($event->lead_id);
                $results = [];
                foreach ($automation->actions as $action) {
                    $type = (string) ($action['type'] ?? '');
                    $results[] = $type === 'create_follow_up'
                        ? $this->createFollowUp($lead, $event, $automation)
                        : ['action' => $type, 'status' => 'handled_by_existing_pipeline'];
                }
                $locked->update(['status' => 'completed', 'result' => $results, 'completed_at' => now()]);
            }, 3);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);
        }
    }

    private function createFollowUp(Lead $lead, IntegrationEvent $event, IntegrationAutomation $automation): array
    {
        $activity = Activity::query()->firstOrCreate(
            [
                'organization_id' => $lead->organization_id, 'subject_type' => Lead::class,
                'subject_id' => $lead->id, 'description' => 'Integration automation event '.$event->id,
            ],
            [
                'created_by' => $lead->created_by, 'assigned_to' => $lead->assigned_to,
                'type' => 'task', 'title' => $automation->name.' follow-up',
                'due_at' => now()->addDay(), 'priority' => 'medium', 'is_done' => false,
            ]
        );

        return ['action' => 'create_follow_up', 'status' => $activity->wasRecentlyCreated ? 'created' : 'already_exists', 'activity_id' => $activity->id];
    }

    private function triggers(IntegrationEvent $event): array
    {
        $triggers = ['new_lead'];
        if ($event->provider === 'meta') {
            $triggers[] = 'form_submitted';
        }
        if ($event->provider === 'whatsapp') {
            $triggers[] = 'whatsapp_enquiry';
        }
        if (in_array($event->provider, ['google', 'microsoft', 'email'], true)) {
            $triggers[] = 'inbound_email';
        }
        if ($event->event_type === 'email_reply' && in_array($event->classification, ['interested', 'meeting_requested', 'requesting_information'], true)) {
            $triggers[] = 'interested_reply';
        }

        return array_values(array_unique($triggers));
    }
}
