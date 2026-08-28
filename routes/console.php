<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
*/

// Release expired wallet reservations every minute.
Schedule::command('wallets:expire-reservations')->everyMinute();

// Publish transactional-outbox events every minute.
Schedule::command('outbox:publish')->everyMinute();

// Resolve ambiguous transactions via provider verification every minute.
Schedule::command('transactions:verify-stale')->everyMinute();

// Nightly reconciliation against the mock provider (add real providers here).
Schedule::command('reconciliation:run mock --days=1')->dailyAt('02:00');

// Delete idempotency keys past their expiry — previously nothing did this.
Schedule::command('idempotency:sweep')->hourly();
