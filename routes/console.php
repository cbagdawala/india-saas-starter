<?php

use App\Modules\System\Services\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

// Scheduler entry point (run by `php artisan schedule:work` / the starter-scheduler container).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-batches --hours=48')->daily();

Schedule::command('accounts:purge-unverified')->daily();
Schedule::command('billing:prune-webhook-payloads')->daily();

// Recovers payments whose invoice could not be issued at confirmation time (see InvoiceBackfill).
Schedule::command('invoicing:backfill')->everyFifteenMinutes()->withoutOverlapping()->name('invoicing-backfill');

// /health reports the last run of this heartbeat (stale scheduler = no billing sync).
Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('health-heartbeat');
