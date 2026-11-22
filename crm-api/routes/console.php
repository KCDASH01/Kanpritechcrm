<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Subscription expiry — auto-downgrade Business → Free
|--------------------------------------------------------------------------
| Runs daily at 00:05 (5 minutes past midnight) to catch any subscriptions
| whose end_date crossed midnight. Logs output to storage/logs/laravel.log.
|
| To run manually:   php artisan subscriptions:expire
| To preview:        php artisan subscriptions:expire --dry-run
|
*/
Schedule::command('subscriptions:expire')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('subscriptions:trial-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('promotions:sync-states')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('customer-growth:process')
    ->dailyAt('01:10')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('lead-integrations:maintain')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Artisan::command('lead-imports:maintain', function () {
    $expired = now()->subDays(max(1, (int) config('lead_imports.retention_days')));
    \App\Models\LeadImport::where('created_at', '<', $expired)
        ->whereNotIn('status', ['validating', 'queued', 'processing'])
        ->where(fn ($query) => $query->whereNotNull('file_path')->orWhereNotNull('mapping'))
        ->eachById(function ($import) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($import) {
                $locked = \App\Models\LeadImport::lockForUpdate()->find($import->id);
                if (! $locked || in_array($locked->status, ['validating', 'queued', 'processing'], true)) {
                    return;
                }
                if ($locked->file_path && ! \Illuminate\Support\Facades\Storage::disk('local')->delete($locked->file_path)) {
                    return;
                }
                $locked->rows()->update(['payload' => null]);
                $changes = ['file_path' => null, 'sheets' => [], 'mapping' => null];
                if (in_array($locked->status, ['uploaded', 'ready'], true)) {
                    $changes += ['status' => 'failed', 'error' => 'The unconfirmed upload expired. Upload a fresh copy to continue.', 'completed_at' => now()];
                }
                $locked->update($changes);
            });
        });

    // Recover a committed transition if the request stopped before dispatching.
    \App\Models\LeadImport::whereIn('status', ['validating', 'queued', 'processing'])
        ->where('updated_at', '<', now()->subMinutes(25))
        ->eachById(function ($import) {
            \App\Jobs\ProcessLeadImport::dispatch($import->id, $import->mapping['revision'], $import->status === 'validating')
                ->onConnection(config('lead_imports.queue_connection'))->onQueue('lead-imports');
            $import->touch();
        });
})->purpose('Remove expired lead import files and recover interrupted dispatches');

Schedule::command('lead-imports:maintain')->everyTenMinutes()->withoutOverlapping();
