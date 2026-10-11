<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('last_synced_at');
            $table->timestamp('last_event_at')->nullable()->after('paused_at');
            $table->timestamp('health_checked_at')->nullable()->after('last_event_at');
            $table->string('webhook_status', 32)->default('pending')->after('health_checked_at');
            $table->text('sync_cursor')->nullable()->after('webhook_status');
        });

        Schema::create('integration_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('connection_id')->index();
            $table->string('provider', 32);
            $table->string('asset_type', 48);
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');
            $table->string('status', 32)->default('available');
            $table->boolean('is_selected')->default(false);
            $table->text('access_token')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'asset_type', 'external_id'], 'li_asset_connection_type_ext_unique');
            $table->index(['organization_id', 'provider', 'is_selected'], 'li_asset_org_provider_selected_idx');
        });

        Schema::create('integration_connection_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('connection_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64);
            $table->string('status', 32);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'li_audit_org_created_idx');
        });

        Schema::create('integration_automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('name');
            $table->string('template_key', 64)->nullable();
            $table->string('trigger', 64);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'trigger', 'is_active'], 'li_automation_org_trigger_active_idx');
        });

        Schema::create('integration_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('automation_id')->index();
            $table->unsignedBigInteger('integration_event_id')->nullable()->index();
            $table->string('idempotency_key');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'idempotency_key'], 'li_automation_run_dedupe_unique');
            $table->index(['organization_id', 'status', 'created_at'], 'li_automation_run_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_automation_runs');
        Schema::dropIfExists('integration_automations');
        Schema::dropIfExists('integration_connection_audits');
        Schema::dropIfExists('integration_assets');

        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'last_event_at', 'health_checked_at', 'webhook_status', 'sync_cursor']);
        });
    }
};
