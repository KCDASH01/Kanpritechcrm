<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionAuditEvent;
use App\Models\SubscriptionGatewayEvent;
use App\Models\SubscriptionTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SubscriptionWebhookService
{
    public function process(SubscriptionGatewayEvent $event): void
    {
        try {
            DB::transaction(function () use ($event) {
                $payload = $event->payload;
                $remote = data_get($payload, 'payload.subscription.entity');
                $payment = data_get($payload, 'payload.payment.entity');
                $gatewayId = $remote['id'] ?? $event->gateway_subscription_id;
                $subscription = Subscription::where('gateway_subscription_id', $gatewayId)->lockForUpdate()->first();
                if (! $subscription) {
                    $event->update(['processing_status' => 'ignored', 'processed_at' => now()]);

                    return;
                }

                $event->update(['organization_id' => $subscription->organization_id, 'subscription_id' => $subscription->id]);
                $before = $subscription->toArray();
                $type = $event->event_type;
                if (in_array($type, ['subscription.authenticated', 'subscription.activated', 'subscription.charged'], true)) {
                    $expectedPlanId = (string) ($subscription->gateway_plan_id ?? '');
                    $remotePlanId = (string) ($remote['plan_id'] ?? '');
                    if ($expectedPlanId === '' || $remotePlanId === '' || ! hash_equals($expectedPlanId, $remotePlanId)) {
                        throw new RuntimeException('Razorpay subscription plan does not match the locally authorized plan.');
                    }
                }

                $eventTimestamp = data_get($payload, 'created_at');
                $lastTimestamp = data_get($subscription->external_metadata, 'last_gateway_event_at');
                if ($eventTimestamp && $lastTimestamp && (int) $eventTimestamp < (int) $lastTimestamp) {
                    $event->update(['processing_status' => 'ignored', 'processed_at' => now(), 'processing_error' => 'Older than the last processed gateway event.']);

                    return;
                }

                if (in_array($type, ['subscription.authenticated'], true) && ! $subscription->authorized_at) {
                    $trialEnd = $this->date($remote['start_at'] ?? null) ?? $subscription->trial_ends_at;
                    $subscription->update([
                        'provider_status' => 'authenticated', 'authorized_at' => now(),
                        'trial_started_at' => now(), 'trial_ends_at' => $trialEnd,
                        'next_charge_at' => $this->date($remote['charge_at'] ?? null) ?? $trialEnd,
                        'start_date' => now(), 'end_date' => $trialEnd, 'access_until' => $trialEnd,
                        'status' => 'trialing', 'is_active' => true,
                        'gateway_customer_id' => $remote['customer_id'] ?? null,
                    ]);
                } elseif (in_array($type, ['subscription.activated', 'subscription.charged'], true)) {
                    $periodEnd = $this->date($remote['current_end'] ?? null);
                    $subscription->update([
                        'provider_status' => 'active', 'status' => 'active', 'is_active' => true,
                        'current_period_start' => $this->date($remote['current_start'] ?? null),
                        'current_period_end' => $periodEnd, 'next_charge_at' => $this->date($remote['charge_at'] ?? null),
                        'end_date' => $periodEnd, 'access_until' => $periodEnd,
                        'grace_ends_at' => null, 'last_payment_failed_at' => null,
                    ]);
                } elseif (in_array($type, ['subscription.pending', 'subscription.halted', 'payment.failed'], true)) {
                    $subscription->update([
                        'provider_status' => $type === 'subscription.halted' ? 'halted' : 'pending',
                        'last_payment_failed_at' => now(), 'grace_ends_at' => now()->addDays(3),
                        'access_until' => now()->addDays(3),
                    ]);
                } elseif ($type === 'subscription.cancelled') {
                    $accessUntil = $subscription->access_until ?? $subscription->trial_ends_at ?? $subscription->current_period_end;
                    $expired = ! $accessUntil || $accessUntil->isPast();
                    $subscription->update([
                        'provider_status' => 'cancelled', 'cancelled_at' => $subscription->cancelled_at ?? now(),
                        'status' => $expired ? 'cancelled' : $subscription->status,
                        'is_active' => ! $expired,
                    ]);
                } elseif (in_array($type, ['subscription.completed', 'subscription.expired'], true)) {
                    $subscription->update(['provider_status' => $remote['status'] ?? 'expired', 'status' => 'expired', 'is_active' => false]);
                }

                if ($payment && ! empty($payment['id'])) {
                    SubscriptionTransaction::updateOrCreate(
                        ['gateway' => 'razorpay', 'gateway_payment_id' => $payment['id']],
                        [
                            'organization_id' => $subscription->organization_id,
                            'subscription_id' => $subscription->id,
                            'gateway_invoice_id' => $payment['invoice_id'] ?? null,
                            'type' => str_contains($type, 'failed') ? 'renewal_failure' : ($subscription->status === 'trialing' ? 'authorization' : 'subscription_charge'),
                            'status' => $payment['status'] ?? 'unknown',
                            'amount_minor' => (int) ($payment['amount'] ?? 0),
                            'currency' => $payment['currency'] ?? $subscription->currency,
                            'failure_code' => $payment['error_code'] ?? null,
                            'failure_description' => $payment['error_description'] ?? null,
                            'occurred_at' => $this->date($payment['created_at'] ?? null) ?? now(),
                        ]
                    );
                }

                if ($eventTimestamp) {
                    $subscription->update([
                        'external_metadata' => array_merge($subscription->external_metadata ?? [], [
                            'last_gateway_event_at' => (int) $eventTimestamp,
                            'last_gateway_event_id' => $event->event_id,
                        ]),
                    ]);
                }

                SubscriptionAuditEvent::create([
                    'organization_id' => $subscription->organization_id, 'subscription_id' => $subscription->id,
                    'event_type' => $type, 'before' => $before, 'after' => $subscription->fresh()->toArray(),
                    'metadata' => ['gateway_event_id' => $event->event_id], 'created_at' => now(),
                ]);
                $event->update(['processing_status' => 'processed', 'processed_at' => now()]);
            });
        } catch (Throwable $exception) {
            $event->update(['processing_status' => 'failed', 'processing_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    private function date(mixed $timestamp): ?Carbon
    {
        return $timestamp
            ? Carbon::createFromTimestamp((int) $timestamp)->setTimezone(config('app.timezone'))
            : null;
    }
}
