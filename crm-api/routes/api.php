<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SSOController;
use App\Http\Controllers\Billing\BillingCatalogueController;
use App\Http\Controllers\Billing\BillingDocumentController;
use App\Http\Controllers\Billing\BillingPaymentController;
use App\Http\Controllers\Billing\BillingPdfController;
use App\Http\Controllers\Billing\BillingReceiptController;
use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\CustomerGrowth\CustomerGrowthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Deal\DealController;
use App\Http\Controllers\Deal\DealPaymentController;
use App\Http\Controllers\Department\DepartmentController;
use App\Http\Controllers\EmailTemplate\EmailTemplateController;
use App\Http\Controllers\Employee\EmployeeController;
use App\Http\Controllers\FollowUp\FollowUpController;
use App\Http\Controllers\GeographicAnalytics\GeographicAnalyticsController;
use App\Http\Controllers\Important\ImportantController;
use App\Http\Controllers\Lead\LeadController;
use App\Http\Controllers\LeadIntegration\LeadIntegrationController;
use App\Http\Controllers\LeadIntegration\OAuthController as LeadIntegrationOAuthController;
use App\Http\Controllers\LeadIntegration\WebhookController as LeadIntegrationWebhookController;
use App\Http\Controllers\Meeting\MeetingController;
use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Pipeline\PipelineController;
use App\Http\Controllers\Pipeline\StageController;
use App\Http\Controllers\Proposal\ProposalController;
use App\Http\Controllers\RecurringBusiness\RecurringBusinessController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\SalesTarget\SalesTargetController;
use App\Http\Controllers\Subscription\PaymentHistoryController;
use App\Http\Controllers\Subscription\SeatController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('sso/login', [SSOController::class, 'login']);

Route::get('webhooks/meta', [LeadIntegrationWebhookController::class, 'verifyMeta']);
Route::post('webhooks/meta', [LeadIntegrationWebhookController::class, 'meta']);
Route::post('webhooks/whatsapp', [LeadIntegrationWebhookController::class, 'whatsapp']);
Route::post('webhooks/email/inbound', [LeadIntegrationWebhookController::class, 'inboundEmail']);
Route::post('webhooks/google/gmail', [LeadIntegrationWebhookController::class, 'gmail']);
Route::match(['get', 'post'], 'webhooks/microsoft/mail', [LeadIntegrationWebhookController::class, 'microsoft']);
Route::get('lead-integrations/oauth/{provider}/callback', [LeadIntegrationOAuthController::class, 'callback']);

// ── Authenticated routes ───────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Me
    Route::get('me', [MeController::class, 'show']);
    Route::put('me', [MeController::class, 'update']);
    Route::put('me/password', [MeController::class, 'changePassword']);
    Route::post('me/set-password', [MeController::class, 'setPassword']);

    // Organization settings
    Route::get('organization/receipt-settings', [MeController::class, 'getReceiptSettings']);
    Route::put('organization/receipt-settings', [MeController::class, 'updateReceiptSettings']);

    // Subscription (no plan gate — anyone can view their sub)
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('subscription/upgrade', [SubscriptionController::class, 'upgrade']);
    Route::post('subscription/upgrade/order', [SubscriptionController::class, 'createUpgradeOrder']);
    Route::post('subscription/subscribe/order', [SubscriptionController::class, 'subscribeOrder']);
    Route::post('subscription/subscribe/verify', [SubscriptionController::class, 'subscribeVerify']);
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel']);

    // Payment history (all authenticated users — read-only)
    Route::get('subscription/payments', [PaymentHistoryController::class, 'index']);
    Route::get('subscription/payments/{payment}', [PaymentHistoryController::class, 'show']);

    // Seat billing (owner only — no plan gate needed, controller validates plan)
    Route::middleware('role:owner')->group(function () {
        Route::post('subscription/seats/add', [SeatController::class, 'add']);
        Route::post('subscription/seats/verify', [SeatController::class, 'verify']);
        Route::delete('subscription/seats/remove', [SeatController::class, 'remove']);
    });

    // ── Routes that require an active subscription ─────────────────────────────
    Route::middleware('check.subscription')->group(function () {

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/performance-details', [DashboardController::class, 'performanceDetails']);

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
        });

        // Pipelines & Stages
        Route::apiResource('pipelines', PipelineController::class);
        Route::post('pipelines/{pipeline}/stages', [StageController::class, 'store']);
        Route::put('pipelines/{pipeline}/stages/{stage}', [StageController::class, 'update']);
        Route::delete('pipelines/{pipeline}/stages/{stage}', [StageController::class, 'destroy']);
        Route::post('pipelines/{pipeline}/stages/reorder', [StageController::class, 'reorder']);

        // Leads
        Route::get('leads/department-counts', [LeadController::class, 'departmentCounts']);
        Route::get('leads/{lead}/timeline', [LeadController::class, 'timeline']);
        Route::post('leads/{lead}/convert', [LeadController::class, 'convertToDeal']);
        Route::apiResource('leads', LeadController::class);

        // Canonical clients and their complete commercial history
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/{client}', [ClientController::class, 'show']);

        // Deals
        Route::patch('deals/{deal}/stage', [DealController::class, 'moveStage']);
        Route::get('deals/{deal}/payments', [DealPaymentController::class, 'index']);
        Route::post('deals/{deal}/payments', [DealPaymentController::class, 'store']);
        Route::put('deals/{deal}/payments/{payment}', [DealPaymentController::class, 'update']);
        Route::apiResource('deals', DealController::class);

        // Converted recurring contracts (actual revenue remains in deal_payments)
        Route::get('recurring-businesses', [RecurringBusinessController::class, 'index']);
        Route::get('recurring-businesses/{recurringBusiness}', [RecurringBusinessController::class, 'show']);
        Route::put('recurring-businesses/{recurringBusiness}', [RecurringBusinessController::class, 'update']);

        // Follow-ups (lead activities with due dates)
        Route::get('follow-ups', [FollowUpController::class, 'index']);

        // Meetings (leads with meeting status)
        Route::get('meetings', [MeetingController::class, 'index']);

        // Important (leads with important status)
        Route::get('important', [ImportantController::class, 'index']);

        // Calendar (scheduled follow-ups & meetings from leads)
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('calendar/collections', [CalendarController::class, 'collections']);

        // Customer Billing & Invoicing foundation. This is intentionally separate
        // from /billing, which remains subscription and seat billing.
        Route::prefix('billing-invoicing')->group(function () {
            Route::get('overview', [BillingDocumentController::class, 'overview']);
            Route::get('documents', [BillingDocumentController::class, 'index']);
            Route::post('documents', [BillingDocumentController::class, 'store']);
            Route::get('documents/{billingDocument}', [BillingDocumentController::class, 'show']);
            Route::put('documents/{billingDocument}', [BillingDocumentController::class, 'update']);
            Route::post('documents/{billingDocument}/submit', [BillingDocumentController::class, 'submit']);
            Route::post('documents/{billingDocument}/approve', [BillingDocumentController::class, 'approve']);
            Route::post('documents/{billingDocument}/issue', [BillingDocumentController::class, 'issue']);
            Route::post('documents/{billingDocument}/convert-to-invoice', [BillingDocumentController::class, 'convert']);
            Route::post('documents/{billingDocument}/link-commercial', [BillingDocumentController::class, 'linkCommercial']);
            Route::post('documents/{billingDocument}/void', [BillingDocumentController::class, 'void']);
            Route::get('catalogue', [BillingCatalogueController::class, 'index']);
            Route::post('catalogue', [BillingCatalogueController::class, 'store']);
            Route::put('catalogue/{catalogueItem}', [BillingCatalogueController::class, 'update']);
            Route::delete('catalogue/{catalogueItem}', [BillingCatalogueController::class, 'destroy']);
            Route::get('payments', [BillingPaymentController::class, 'index']);
            Route::post('documents/{billingDocument}/allocations', [BillingPaymentController::class, 'allocate']);
            Route::post('allocations/{billingPaymentAllocation}/reverse', [BillingPaymentController::class, 'reverse']);
            Route::get('receipts', [BillingReceiptController::class, 'index']);
            Route::post('receipts', [BillingReceiptController::class, 'store']);
            Route::get('consolidated-receipts', [BillingReceiptController::class, 'consolidatedIndex']);
            Route::post('consolidated-receipts', [BillingReceiptController::class, 'consolidatedStore']);
            Route::get('settings', [BillingSettingsController::class, 'show']);
            Route::put('settings', [BillingSettingsController::class, 'update']);
            Route::get('templates', [BillingSettingsController::class, 'templates']);
            Route::post('settings/assets/{type}', [BillingSettingsController::class, 'uploadAsset']);
            Route::get('permissions', [BillingSettingsController::class, 'getPermissions']);
            Route::put('permissions', [BillingSettingsController::class, 'updatePermissions']);
            Route::get('sequences', [BillingSettingsController::class, 'sequences']);
            Route::put('sequences/{documentType}', [BillingSettingsController::class, 'updateSequence']);
            Route::get('sequences/{documentType}/preview', [BillingSettingsController::class, 'previewSequence']);
            Route::post('calculate', [BillingSettingsController::class, 'calculate']);
            Route::get('audit-history', [BillingSettingsController::class, 'audits']);
            Route::post('bank-accounts', [BillingSettingsController::class, 'storeBank']);
            Route::put('bank-accounts/{bank}', [BillingSettingsController::class, 'updateBank']);
            Route::delete('bank-accounts/{bank}', [BillingSettingsController::class, 'destroyBank']);
            Route::get('templates/{template}/preview', [BillingPdfController::class, 'template']);
            Route::get('documents/{billingDocument}/pdf', [BillingPdfController::class, 'document']);
            Route::get('receipts/{billingReceipt}/pdf', [BillingPdfController::class, 'receipt']);
            Route::get('consolidated-receipts/{billingConsolidatedReceipt}/pdf', [BillingPdfController::class, 'consolidatedReceipt']);
        });

        // Activities
        Route::patch('activities/{activity}/done', [ActivityController::class, 'markDone']);
        Route::apiResource('activities', ActivityController::class);

        // Notes
        Route::apiResource('notes', NoteController::class)->except(['show']);

        // Sales Targets
        Route::prefix('sales-targets')->group(function () {
            Route::get('/', [SalesTargetController::class, 'index']);
            Route::get('/my-progress', [SalesTargetController::class, 'myProgress']);
            Route::get('/leaderboard', [SalesTargetController::class, 'leaderboard']);
            Route::get('/leaderboard-settings', [SalesTargetController::class, 'getLeaderboardSettings']);
            Route::put('/leaderboard-settings', [SalesTargetController::class, 'updateLeaderboardSettings']);
            Route::post('/', [SalesTargetController::class, 'upsert']);
            Route::get('/{user}/progress', [SalesTargetController::class, 'userProgress']);
            Route::get('/{salesTarget}/details', [SalesTargetController::class, 'details']);
            Route::patch('/{salesTarget}/received', [SalesTargetController::class, 'updateReceived']);
        });

        // WhatsApp Templates (read-only for all users; managed by super admin)
        Route::get('whatsapp-templates', fn () => response()->json([
            'data' => WhatsAppTemplate::where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'message']),
        ]));

        // ── Business-plan only ───────────────────────────────────────────────
        Route::middleware('business.only')->group(function () {
            Route::apiResource('employees', EmployeeController::class);
            Route::apiResource('departments', DepartmentController::class);

            // Advanced reports (Business plan feature)
            Route::get('reports', [ReportsController::class, 'index']);
            Route::get('reports/leads', [ReportsController::class, 'leadReport']);
            Route::get('reports/leads/export', [ReportsController::class, 'exportLeadReport']);
            Route::get('reports/revenue', [ReportsController::class, 'revenueReport']);
            Route::get('reports/revenue/export', [ReportsController::class, 'exportRevenueReport']);

            // Standalone geographic business analytics (read-only)
            Route::get('geographic-analytics', [GeographicAnalyticsController::class, 'overview']);
            Route::get('geographic-analytics/deals', [GeographicAnalyticsController::class, 'deals']);
            Route::get('geographic-analytics/export', [GeographicAnalyticsController::class, 'export']);

            // Customer growth and retention (isolated from existing financial workflows)
            Route::get('customer-growth', [CustomerGrowthController::class, 'index']);
            Route::post('customer-growth/refresh', [CustomerGrowthController::class, 'refresh']);
            Route::get('customer-growth/recommendations/{recommendation}/deals', [CustomerGrowthController::class, 'candidateDeals']);
            Route::patch('customer-growth/recommendations/{recommendation}', [CustomerGrowthController::class, 'updateRecommendation']);
            Route::post('customer-growth/recommendations/{recommendation}/link-deal', [CustomerGrowthController::class, 'linkDeal']);
            Route::post('customer-growth/retention-tasks', [CustomerGrowthController::class, 'storeRetentionTask']);
            Route::patch('customer-growth/retention-tasks/{task}/complete', [CustomerGrowthController::class, 'completeRetentionTask']);
            Route::get('customer-growth/settings', [CustomerGrowthController::class, 'settings']);
            Route::put('customer-growth/settings', [CustomerGrowthController::class, 'updateSettings']);

            // Unified lead integrations (all writes are owner/admin checked in the controller)
            Route::get('lead-integrations', [LeadIntegrationController::class, 'index']);
            Route::get('lead-integrations/providers', [LeadIntegrationController::class, 'providers']);
            Route::get('lead-integrations/history', [LeadIntegrationController::class, 'history']);
            Route::get('lead-integrations/history/export', [LeadIntegrationController::class, 'exportHistory']);
            Route::post('lead-integrations/history/{event}/retry', [LeadIntegrationController::class, 'retryEvent']);
            Route::get('lead-integrations/review-queue', [LeadIntegrationController::class, 'reviewQueue']);
            Route::post('lead-integrations/connections', [LeadIntegrationController::class, 'storeConnection']);
            Route::delete('lead-integrations/connections/{connection}', [LeadIntegrationController::class, 'disconnect']);
            Route::post('lead-integrations/connections/{connection}/pause', [LeadIntegrationController::class, 'pause']);
            Route::post('lead-integrations/connections/{connection}/resume', [LeadIntegrationController::class, 'resume']);
            Route::post('lead-integrations/connections/{connection}/test', [LeadIntegrationController::class, 'testConnection']);
            Route::put('lead-integrations/connections/{connection}', [LeadIntegrationController::class, 'updateConnection']);
            Route::post('lead-integrations/connections/{connection}/sync', [LeadIntegrationController::class, 'syncConnection']);
            Route::get('lead-integrations/connections/{connection}/history', [LeadIntegrationController::class, 'connectionHistory']);
            Route::get('lead-integrations/connections/{connection}/assets', [LeadIntegrationController::class, 'assets']);
            Route::put('lead-integrations/connections/{connection}/assets', [LeadIntegrationController::class, 'selectAssets']);
            Route::post('lead-integrations/connections/{connection}/meta-history', [LeadIntegrationController::class, 'syncMetaHistory']);
            Route::get('lead-integrations/oauth/{provider}', [LeadIntegrationOAuthController::class, 'authorizeProvider']);
            Route::post('lead-integrations/campaigns', [LeadIntegrationController::class, 'storeCampaign']);
            Route::put('lead-integrations/campaigns/{campaign}', [LeadIntegrationController::class, 'updateCampaign']);
            Route::delete('lead-integrations/campaigns/{campaign}', [LeadIntegrationController::class, 'destroyCampaign']);
            Route::post('lead-integrations/campaigns/{campaign}/recipients', [LeadIntegrationController::class, 'storeRecipients']);
            Route::post('lead-integrations/routing-rules', [LeadIntegrationController::class, 'storeRule']);
            Route::put('lead-integrations/routing-rules/{rule}', [LeadIntegrationController::class, 'updateRule']);
            Route::delete('lead-integrations/routing-rules/{rule}', [LeadIntegrationController::class, 'destroyRule']);
            Route::post('lead-integrations/routing-rules/preview', [LeadIntegrationController::class, 'routingPreview']);
            Route::get('lead-integrations/automations', [LeadIntegrationController::class, 'automations']);
            Route::post('lead-integrations/automations', [LeadIntegrationController::class, 'storeAutomation']);
            Route::patch('lead-integrations/automations/{automation}', [LeadIntegrationController::class, 'updateAutomation']);
            Route::post('lead-integrations/automations/{automation}/test', [LeadIntegrationController::class, 'testAutomation']);
            Route::put('lead-integrations/settings', [LeadIntegrationController::class, 'updateSettings']);
            Route::post('lead-integrations/review-queue/{review}/resolve', [LeadIntegrationController::class, 'resolveReview']);

            // Bulk lead import (Business plan feature)
            Route::post('leads/bulk-import', [LeadController::class, 'bulkImport']);

            // Proposals (Business/Enterprise only)
            Route::post('leads/{lead}/proposals/generate', [ProposalController::class, 'generate']);
            Route::get('leads/{lead}/proposals', [ProposalController::class, 'index']);
            Route::get('proposals/{proposal}', [ProposalController::class, 'show']);
            Route::put('proposals/{proposal}', [ProposalController::class, 'update']);
            Route::delete('proposals/{proposal}', [ProposalController::class, 'destroy']);

            // Sales Targets (Business/Enterprise only)
            Route::prefix('sales-targets')->group(function () {
                Route::get('/', [SalesTargetController::class, 'index']);
                Route::get('/my-progress', [SalesTargetController::class, 'myProgress']);
                Route::get('/leaderboard', [SalesTargetController::class, 'leaderboard']);
                Route::get('/leaderboard-settings', [SalesTargetController::class, 'getLeaderboardSettings']);
                Route::put('/leaderboard-settings', [SalesTargetController::class, 'updateLeaderboardSettings']);
                Route::post('/', [SalesTargetController::class, 'upsert']);
                Route::get('/{user}/progress', [SalesTargetController::class, 'userProgress']);
                Route::get('/{salesTarget}/details', [SalesTargetController::class, 'details']);
                Route::patch('/{salesTarget}/received', [SalesTargetController::class, 'updateReceived']);
            });

            // Email Templates (Business/Enterprise only)
            Route::apiResource('email-templates', EmailTemplateController::class);
        });
    });
});
