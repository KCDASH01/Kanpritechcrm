<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('connected_by')->nullable()->index();
            $table->string('provider', 32);
            $table->string('name');
            $table->string('status', 32)->default('not_connected');
            $table->string('external_account_id')->nullable();
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['organization_id', 'provider', 'external_account_id'],
                'li_conn_org_provider_account_unique'
            );
            $table->index(['organization_id', 'provider', 'status'], 'li_conn_org_provider_status_idx');
        });

        Schema::create('integration_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('connection_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('platform', 32);
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('campaign_type', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('target_market')->nullable();
            $table->string('service')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'platform', 'external_id'],
                'li_campaign_org_platform_ext_unique'
            );
            $table->index(['organization_id', 'platform', 'status'], 'li_campaign_org_platform_status_idx');
        });

        Schema::create('lead_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('backup_user_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
            $table->string('name');
            $table->string('source_type', 64);
            $table->string('source_key')->nullable();
            $table->string('strategy', 32)->default('fixed');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'source_type', 'source_key', 'is_active'],
                'li_route_source_active_idx'
            );
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('connection_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('provider', 32);
            $table->string('external_event_id');
            $table->string('event_type', 64);
            $table->string('status', 32)->default('pending');
            $table->string('classification', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('extracted_data')->nullable();
            $table->string('assignment_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'li_event_provider_external_unique');
            $table->index(['organization_id', 'status', 'created_at'], 'li_event_org_status_created_idx');
        });

        Schema::create('lead_attributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('integration_event_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('platform', 32);
            $table->string('source_account_id')->nullable();
            $table->string('external_lead_id')->nullable();
            $table->string('external_message_id')->nullable();
            $table->string('campaign_external_id')->nullable();
            $table->string('adset_external_id')->nullable();
            $table->string('ad_external_id')->nullable();
            $table->string('form_external_id')->nullable();
            $table->boolean('is_original')->default(false);
            $table->timestamp('captured_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'platform', 'external_lead_id'],
                'li_attr_org_platform_lead_unique'
            );
            $table->index(['lead_id', 'captured_at'], 'li_attr_lead_captured_idx');
        });

        Schema::create('integration_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('email');
            $table->string('message_id')->nullable();
            $table->string('thread_id')->nullable();
            $table->string('status', 32)->default('sent');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'message_id'], 'li_recipient_campaign_message_unique');
            $table->unique(['campaign_id', 'email'], 'li_recipient_campaign_email_unique');
            $table->index(['organization_id', 'email'], 'li_recipient_org_email_idx');
        });

        Schema::create('integration_review_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('integration_event_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('linked_lead_id')->nullable();
            $table->string('reason', 64);
            $table->string('status', 32)->default('pending');
            $table->json('source_summary')->nullable();
            $table->json('extracted_data')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at'], 'li_review_org_status_created_idx');
        });

        Schema::create('lead_integration_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique('li_settings_org_unique');
            $table->unsignedBigInteger('default_owner_id')->nullable();
            $table->boolean('meta_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('auto_import_enabled')->default(true);
            $table->boolean('review_unmatched')->default(true);
            $table->unsignedInteger('raw_content_retention_days')->default(30);
            $table->json('field_mappings')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_integration_settings');
        Schema::dropIfExists('integration_review_items');
        Schema::dropIfExists('integration_campaign_recipients');
        Schema::dropIfExists('lead_attributions');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('lead_routing_rules');
        Schema::dropIfExists('integration_campaigns');
        Schema::dropIfExists('integration_connections');
    }
};
