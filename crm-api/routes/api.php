<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SSOController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Deal\DealController;
use App\Http\Controllers\Deal\DealPaymentController;
use App\Http\Controllers\Department\DepartmentController;
use App\Http\Controllers\EmailTemplate\EmailTemplateController;
use App\Http\Controllers\Employee\EmployeeController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\FollowUp\FollowUpController;
use App\Http\Controllers\Important\ImportantController;
use App\Http\Controllers\Meeting\MeetingController;
use App\Http\Controllers\Lead\LeadController;
use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Pipeline\PipelineController;
use App\Http\Controllers\Pipeline\StageController;
use App\Http\Controllers\Proposal\ProposalController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\SalesTarget\SalesTargetController;
use App\Http\Controllers\Subscription\PaymentHistoryController;
use App\Http\Controllers\Subscription\SeatController;
use App\Http\Controllers\Subscription\SubscriptionController;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('register',         [AuthController::class, 'register']);
    Route::post('login',            [AuthController::class, 'login']);
    Route::post('forgot-password',  [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',   [AuthController::class, 'resetPassword']);
});

Route::post('sso/login', [SSOController::class, 'login']);

// ── Authenticated routes ───────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Me
    Route::get('me',               [MeController::class, 'show']);
    Route::put('me',               [MeController::class, 'update']);
    Route::put('me/password',      [MeController::class, 'changePassword']);
    Route::post('me/set-password', [MeController::class, 'setPassword']);

    // Organization settings
    Route::get('organization/receipt-settings', [MeController::class, 'getReceiptSettings']);
    Route::put('organization/receipt-settings', [MeController::class, 'updateReceiptSettings']);

    // Subscription (no plan gate — anyone can view their sub)
    Route::get('subscription',                        [SubscriptionController::class, 'show']);
    Route::post('subscription/upgrade',               [SubscriptionController::class, 'upgrade']);
    Route::post('subscription/upgrade/order',         [SubscriptionController::class, 'createUpgradeOrder']);
    Route::post('subscription/subscribe/order',       [SubscriptionController::class, 'subscribeOrder']);
    Route::post('subscription/subscribe/verify',      [SubscriptionController::class, 'subscribeVerify']);
    Route::post('subscription/cancel',                [SubscriptionController::class, 'cancel']);

    // Payment history (all authenticated users — read-only)
    Route::get('subscription/payments',            [PaymentHistoryController::class, 'index']);
    Route::get('subscription/payments/{payment}',  [PaymentHistoryController::class, 'show']);

    // Seat billing (owner only — no plan gate needed, controller validates plan)
    Route::middleware('role:owner')->group(function () {
        Route::post('subscription/seats/add',      [SeatController::class, 'add']);
        Route::post('subscription/seats/verify',   [SeatController::class, 'verify']);
        Route::delete('subscription/seats/remove', [SeatController::class, 'remove']);
    });

    // ── Routes that require an active subscription ─────────────────────────────
    Route::middleware('check.subscription')->group(function () {

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/',                        [NotificationController::class, 'index']);
            Route::get('/unread-count',            [NotificationController::class, 'unreadCount']);
            Route::post('/read-all',               [NotificationController::class, 'markAllRead']);
            Route::patch('/{notification}/read',   [NotificationController::class, 'markRead']);
        });

        // Pipelines & Stages
        Route::apiResource('pipelines', PipelineController::class);
        Route::post('pipelines/{pipeline}/stages',                  [StageController::class, 'store']);
        Route::put('pipelines/{pipeline}/stages/{stage}',           [StageController::class, 'update']);
        Route::delete('pipelines/{pipeline}/stages/{stage}',        [StageController::class, 'destroy']);
        Route::post('pipelines/{pipeline}/stages/reorder',          [StageController::class, 'reorder']);

        // Leads
        Route::get('leads/{lead}/timeline',       [LeadController::class, 'timeline']);
        Route::post('leads/{lead}/convert',       [LeadController::class, 'convertToDeal']);
        Route::apiResource('leads',               LeadController::class);

        // Deals
        Route::patch('deals/{deal}/stage',          [DealController::class, 'moveStage']);
        Route::get('deals/{deal}/payments',         [DealPaymentController::class, 'index']);
        Route::post('deals/{deal}/payments',        [DealPaymentController::class, 'store']);
        Route::apiResource('deals',                 DealController::class);

        // Follow-ups (lead activities with due dates)
        Route::get('follow-ups', [FollowUpController::class, 'index']);

        // Meetings (leads with meeting status)
        Route::get('meetings', [MeetingController::class, 'index']);

        // Important (leads with important status)
        Route::get('important', [ImportantController::class, 'index']);

        // Calendar (scheduled follow-ups & meetings from leads)
        Route::get('calendar', [CalendarController::class, 'index']);

        // Activities
        Route::patch('activities/{activity}/done', [ActivityController::class, 'markDone']);
        Route::apiResource('activities',            ActivityController::class);

        // Notes
        Route::apiResource('notes', NoteController::class)->except(['show']);

        // Sales Targets
        Route::prefix('sales-targets')->group(function () {
            Route::get('/',                          [SalesTargetController::class, 'index']);
            Route::get('/my-progress',               [SalesTargetController::class, 'myProgress']);
            Route::post('/',                         [SalesTargetController::class, 'upsert']);
            Route::get('/{user}/progress',           [SalesTargetController::class, 'userProgress']);
            Route::patch('/{salesTarget}/received',  [SalesTargetController::class, 'updateReceived']);
        });

        // WhatsApp Templates (read-only for all users; managed by super admin)
        Route::get('whatsapp-templates', fn () => response()->json([
            'data' => \App\Models\WhatsAppTemplate::where('is_active', true)
                        ->orderBy('sort_order')->orderBy('id')
                        ->get(['id', 'name', 'message']),
        ]));

        // ── Business-plan only ───────────────────────────────────────────────
        Route::middleware('business.only')->group(function () {
            Route::apiResource('employees',   EmployeeController::class);
            Route::apiResource('departments', DepartmentController::class);

            // Advanced reports (Business plan feature)
            Route::get('reports', [ReportsController::class, 'index']);
            Route::get('reports/leads', [ReportsController::class, 'leadReport']);
            Route::get('reports/leads/export', [ReportsController::class, 'exportLeadReport']);
            Route::get('reports/revenue', [ReportsController::class, 'revenueReport']);
            Route::get('reports/revenue/export', [ReportsController::class, 'exportRevenueReport']);

            // Bulk lead import (Business plan feature)
            Route::post('leads/bulk-import', [LeadController::class, 'bulkImport']);

            // Proposals (Business/Enterprise only)
            Route::post('leads/{lead}/proposals/generate', [ProposalController::class, 'generate']);
            Route::get('leads/{lead}/proposals',           [ProposalController::class, 'index']);
            Route::get('proposals/{proposal}',             [ProposalController::class, 'show']);
            Route::put('proposals/{proposal}',             [ProposalController::class, 'update']);
            Route::delete('proposals/{proposal}',          [ProposalController::class, 'destroy']);

            // Sales Targets (Business/Enterprise only)
            Route::prefix('sales-targets')->group(function () {
                Route::get('/',                          [SalesTargetController::class, 'index']);
                Route::get('/my-progress',               [SalesTargetController::class, 'myProgress']);
                Route::post('/',                         [SalesTargetController::class, 'upsert']);
                Route::get('/{user}/progress',           [SalesTargetController::class, 'userProgress']);
                Route::patch('/{salesTarget}/received',  [SalesTargetController::class, 'updateReceived']);
            });

            // Email Templates (Business/Enterprise only)
            Route::apiResource('email-templates', EmailTemplateController::class);
        });
    });
});
