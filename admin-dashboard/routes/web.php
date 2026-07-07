<?php

use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AiConfigController;
use App\Http\Controllers\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

// ── Auth ───────────────────────────────────────────────────────────────────────
Route::get('login',  [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('login', [AdminAuthController::class, 'login'])->name('admin.login.post');

// ── Protected ──────────────────────────────────────────────────────────────────
Route::middleware('auth:admin')->group(function () {

    Route::post('logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Organizations
    Route::get('organizations',                        [OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('organizations/{organization}',         [OrganizationController::class, 'show'])->name('organizations.show');
    Route::post('organizations/{organization}/toggle', [OrganizationController::class, 'toggleActive'])->name('organizations.toggle');

    // Users
    Route::get('users',                  [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}',           [UserController::class, 'show'])->name('users.show');
    Route::post('users/{user}/toggle',   [UserController::class, 'toggleActive'])->name('users.toggle');

    // Plans
    Route::get('plans',                    [PlanController::class, 'index'])->name('plans.index');
    Route::get('plans/create',             [PlanController::class, 'create'])->name('plans.create');
    Route::post('plans',                   [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}/edit',        [PlanController::class, 'edit'])->name('plans.edit');
    Route::put('plans/{plan}',             [PlanController::class, 'update'])->name('plans.update');
    Route::post('plans/{plan}/toggle',     [PlanController::class, 'toggleActive'])->name('plans.toggle');

    // Subscriptions
    Route::get('subscriptions',                                              [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('organizations/{organization}/activate',                      [SubscriptionController::class, 'showActivate'])->name('subscriptions.activate.form');
    Route::post('organizations/{organization}/activate',                     [SubscriptionController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/{subscription}/cancel',                       [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

    // Sales Targets — managed in crm-app under owner/admin role (/targets)

    // AI Config
    Route::get('ai-config',                        [AiConfigController::class, 'index'])->name('ai-config.index');
    Route::post('ai-config',                       [AiConfigController::class, 'store'])->name('ai-config.store');
    Route::put('ai-config/{aiConfig}',             [AiConfigController::class, 'update'])->name('ai-config.update');
    Route::delete('ai-config/{aiConfig}',          [AiConfigController::class, 'destroy'])->name('ai-config.destroy');
    Route::post('ai-config/{aiConfig}/activate',   [AiConfigController::class, 'toggleActive'])->name('ai-config.activate');

    // WhatsApp Templates
    Route::get('whatsapp-templates',                                         [WhatsAppTemplateController::class, 'index'])->name('whatsapp-templates.index');
    Route::post('whatsapp-templates',                                        [WhatsAppTemplateController::class, 'store'])->name('whatsapp-templates.store');
    Route::put('whatsapp-templates/{whatsappTemplate}',                      [WhatsAppTemplateController::class, 'update'])->name('whatsapp-templates.update');
    Route::delete('whatsapp-templates/{whatsappTemplate}',                   [WhatsAppTemplateController::class, 'destroy'])->name('whatsapp-templates.destroy');
    Route::post('whatsapp-templates/{whatsappTemplate}/toggle',              [WhatsAppTemplateController::class, 'toggleActive'])->name('whatsapp-templates.toggle');
});
