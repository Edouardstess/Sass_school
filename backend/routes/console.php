<?php

declare(strict_types=1);

use App\Console\Commands\CalculateStatistics;
use App\Console\Commands\CheckOverdueInvoices;
use App\Console\Commands\CheckSubscriptions;
use App\Console\Commands\CleanupExpiredFiles;
use App\Console\Commands\GenerateRecurringInvoices;
use App\Console\Commands\SendPaymentReminders;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (`php artisan schedule:work`).
|
| Every task is `withoutOverlapping()`: these sweep every tenant, and a slow
| run must not have a second copy start alongside it and double-process. They
| are also `onOneServer()`, so scaling the scheduler horizontally does not
| multiply the work.
|
| Each task is individually runnable and idempotent, so recovering from a
| missed window is `php artisan schoolflow:<task>` and nothing else.
|
*/

// Hourly, because the send window is evaluated in each school's own timezone;
// the command itself decides whether the local hour is right.
Schedule::command(SendPaymentReminders::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Just after midnight UTC, ahead of the reminder sweep that reads the status.
Schedule::command(CheckOverdueInvoices::class)
    ->dailyAt('00:15')
    ->withoutOverlapping()
    ->onOneServer();

// Monthly fees on the first of the month; termly ones are issued manually or
// with an explicit --cadence=termly run, because term boundaries vary.
Schedule::command(GenerateRecurringInvoices::class, ['--cadence=monthly'])
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CheckSubscriptions::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CalculateStatistics::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Off-peak: this deletes from object storage and can be slow.
Schedule::command(CleanupExpiredFiles::class)
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Failed jobs are retried once automatically before a human is involved.
Schedule::command('queue:retry all')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=336')->weekly()->onOneServer();
Schedule::command('auth:clear-resets')->daily()->onOneServer();
