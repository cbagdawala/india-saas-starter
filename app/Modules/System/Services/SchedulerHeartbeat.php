<?php

namespace App\Modules\System\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Written every minute by the scheduler, read by /health (shared cache store, so it works across containers). */
class SchedulerHeartbeat
{
    private const KEY = 'health:scheduler-heartbeat';

    public function beat(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    public function lastRun(): ?Carbon
    {
        $timestamp = Cache::get(self::KEY);

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
