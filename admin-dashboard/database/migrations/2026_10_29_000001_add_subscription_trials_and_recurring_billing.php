<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        // Keep all legacy rows valid while adding Growth for new subscriptions.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free','growth','business','enterprise') NOT NULL DEFAULT 'free'");
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle', 16)->nullable()->after('plan');
            }
            if (! Schema::hasColumn('subscriptions', 'provider_status')) {
                $table->string('provider_status', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('subscriptions', 'gateway_plan_id')) {
                $table->string('gateway_plan_id', 191)->nullable()->after('gateway_subscription_id');
            }
            if (! Schema::hasColumn('subscriptions', 'gateway_customer_id')) {
                $table->string('gateway_customer_id', 191)->nullable()->after('gateway_plan_id');
            }
            if (! Schema::hasColumn('subscriptions', 'authorized_at')) {
                $table->timestamp('authorized_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'trial_started_at')) {
                $table->timestamp('trial_started_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'current_period_start')) {
                $table->timestamp('current_period_start')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'current_period_end')) {
                $table->timestamp('current_period_end')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'next_charge_at')) {
                $table->timestamp('next_charge_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'grace_ends_at')) {
                $table->timestamp('grace_ends_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'cancel_requested_at')) {
                $table->timestamp('cancel_requested_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'access_until')) {
                $table->timestamp('access_until')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false);
            }
            if (! Schema::hasColumn('subscriptions', 'last_payment_failed_at')) {
                $table->timestamp('last_payment_failed_at')->nullable();
            }
        });

        if (! Schema::hasTable('subscription_plan_prices')) {
            Schema::create('subscription_plan_prices', function (Blueprint $table) {
                $table->id();
                $table->string('plan', 32);
                $table->string('billing_cycle', 16);
                $table->char('currency', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->string('gateway', 32)->default('razorpay');
                $table->string('gateway_plan_id', 191)->nullable();
                $table->boolean('tax_exclusive')->default(true);
                $table->boolean('is_public')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamp('effective_from')->nullable();
                $table->timestamp('effective_until')->nullable();
                $table->timestamps();
                $table->unique(['plan', 'billing_cycle', 'currency', 'effective_from'], 'sub_price_version_unique');
                $table->index(['is_active', 'is_public'], 'sub_price_visibility_idx');
            });
        }

        if (! Schema::hasTable('subscription_gateway_events')) {
            Schema::create('subscription_gateway_events', function (Blueprint $table) {
                $table->id();
                $table->string('gateway', 32)->default('razorpay');
                $table->string('event_id', 191);
                $table->string('event_type', 96);
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('gateway_subscription_id', 191)->nullable();
                $table->string('payload_hash', 64);
                $table->json('payload')->nullable();
                $table->string('processing_status', 24)->default('received');
                $table->text('processing_error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['gateway', 'event_id'], 'sub_gateway_event_unique');
                $table->index(['gateway_subscription_id', 'event_type'], 'sub_gateway_lookup_idx');
            });
        }

        if (! Schema::hasTable('subscription_audit_events')) {
            Schema::create('subscription_audit_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('event_type', 96);
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['organization_id', 'created_at'], 'sub_audit_org_date_idx');
            });
        }

        if (! Schema::hasTable('subscription_transactions')) {
            Schema::create('subscription_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('gateway', 32)->default('razorpay');
                $table->string('gateway_payment_id', 191)->nullable();
                $table->string('gateway_invoice_id', 191)->nullable();
                $table->string('type', 32);
                $table->string('status', 32);
                $table->unsignedBigInteger('amount_minor')->default(0);
                $table->char('currency', 3);
                $table->string('failure_code', 96)->nullable();
                $table->text('failure_description')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['gateway', 'gateway_payment_id'], 'sub_transaction_payment_unique');
                $table->index(['organization_id', 'occurred_at'], 'sub_transaction_org_date_idx');
            });
        }

        if (Schema::hasTable('subscription_plan_prices')) {
            $now = now();
            foreach ([
                ['growth', 'monthly', 'INR', 79900], ['growth', 'yearly', 'INR', 799000],
                ['growth', 'monthly', 'USD', 1500], ['growth', 'yearly', 'USD', 15000],
                ['business', 'monthly', 'INR', 199900], ['business', 'yearly', 'INR', 1999000],
                ['business', 'monthly', 'USD', 3900], ['business', 'yearly', 'USD', 39000],
            ] as [$plan, $cycle, $currency, $amount]) {
                DB::table('subscription_plan_prices')->updateOrInsert(
                    [
                        'plan' => $plan,
                        'billing_cycle' => $cycle,
                        'currency' => $currency,
                        'effective_from' => '2026-10-29 00:00:00',
                    ],
                    [
                        'amount_minor' => $amount,
                        'gateway' => 'razorpay',
                        'tax_exclusive' => true,
                        'is_public' => $currency === 'INR',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
        Schema::dropIfExists('subscription_audit_events');
        Schema::dropIfExists('subscription_gateway_events');
        Schema::dropIfExists('subscription_plan_prices');

        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        $columns = [
            'billing_cycle', 'provider_status', 'gateway_plan_id', 'gateway_customer_id',
            'authorized_at', 'trial_started_at', 'trial_ends_at', 'current_period_start',
            'current_period_end', 'next_charge_at', 'grace_ends_at', 'cancel_requested_at',
            'cancelled_at', 'access_until', 'cancel_at_period_end', 'last_payment_failed_at',
        ];
        Schema::table('subscriptions', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // A rollback cannot safely remove Growth while any Growth rows exist.
        if (DB::getDriverName() === 'mysql' && ! DB::table('subscriptions')->where('plan', 'growth')->exists()) {
            DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free','business','enterprise') NOT NULL DEFAULT 'free'");
        }
    }
};
