<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Subscription;
use App\Models\SubscriptionAuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTrialEndingReminders extends Command
{
    protected $signature = 'subscriptions:trial-reminders';

    protected $description = 'Send idempotent 3-day and 1-day trial-ending reminders';

    public function handle(): int
    {
        $sent = 0;
        $subscriptions = Subscription::query()
            ->where('status', 'trialing')
            ->where('is_active', true)
            ->whereNull('cancel_requested_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(3)->addHour()])
            ->with(['organization.owner'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $hours = now()->diffInHours($subscription->trial_ends_at, false);
            $days = $hours <= 25 ? 1 : 3;
            $eventType = "trial_reminder_{$days}d";
            if (SubscriptionAuditEvent::where('subscription_id', $subscription->id)->where('event_type', $eventType)->exists()) {
                continue;
            }

            $owner = $subscription->organization?->owner;
            if (! $owner) {
                continue;
            }

            $amount = number_format((float) $subscription->amount, 2);
            $billingDate = $subscription->trial_ends_at->timezone(config('app.timezone'))->toDayDateTimeString();
            $message = "Your Growneq CRM trial ends on {$billingDate}. {$subscription->currency} {$amount} plus applicable taxes will be billed unless you cancel before the trial ends.";

            Notification::notify($subscription->organization_id, $owner->id, 'subscription', "Trial ends in {$days} day".($days === 1 ? '' : 's'), $message, [
                'subscription_id' => $subscription->id,
                'first_billing_at' => $subscription->trial_ends_at->toIso8601String(),
            ], '/billing');
            Mail::raw($message, fn ($mail) => $mail->to($owner->email)->subject("Growneq CRM trial ends in {$days} day".($days === 1 ? '' : 's')));
            SubscriptionAuditEvent::create([
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'event_type' => $eventType,
                'metadata' => ['channel' => ['in_app', 'email']],
                'created_at' => now(),
            ]);
            $sent++;
        }

        $this->info("Sent {$sent} trial reminder(s).");

        return self::SUCCESS;
    }
}
