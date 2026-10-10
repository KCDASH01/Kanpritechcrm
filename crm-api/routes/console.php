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

Schedule::command('customer-growth:process')
    ->dailyAt('01:10')
    ->withoutOverlapping()
    ->runInBackground();
