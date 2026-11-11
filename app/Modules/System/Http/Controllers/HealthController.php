<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Support\FinancialYear;
use App\Modules\Invoicing\Support\InvoiceNumberAllocator;
use App\Modules\Invoicing\Support\SellerConfig;
use App\Modules\System\Services\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Liveness/readiness probe for uptime monitors. 503 only when the database is down.
 * With a valid X-Health-Token: db, cache, queue (pending/failed), scheduler heartbeat, build SHA.
 * Those details are informational and never fail the probe.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, SchedulerHeartbeat $heartbeat): JsonResponse
    {
        $db = $this->check(fn () => DB::connection()->getPdo());
        $payload = ['status' => $db ? 'ok' : 'degraded'];

        // A real billing driver without a valid invoice seller means paid customers get no invoice: surface it (HTTP stays 200,
        // the app is up; monitors that read the body or the token details see the reason).
        $invoicingProblems = SellerConfig::required() ? SellerConfig::problems() : [];
        if ($db && $invoicingProblems !== []) {
            $payload['status'] = 'degraded';
            $payload['reason'] = 'invoicing_not_configured';
        }

        // Details need HEALTH_TOKEN (>= 32 chars, else never); compared as SHA-256 digests (constant time, any length).
        $token = (string) config('product.health_token');
        $presented = (string) $request->header('X-Health-Token');
        if (strlen($token) >= 32 && $presented !== '' && hash_equals(hash('sha256', $token), hash('sha256', $presented))) {
            $lastRun = $heartbeat->lastRun();
            $staleAfter = (int) config('product.scheduler_stale_after', 300);

            $payload += [
                'db' => $db,
                'cache' => $this->check(fn () => Cache::store()->get('health-probe')),
                'queue' => [
                    'connection' => (string) config('queue.default'),
                    'pending' => $this->value(fn () => Queue::size()),
                    'failed' => $db ? $this->value(fn () => DB::table('failed_jobs')->count()) : null,
                ],
                'scheduler' => [
                    'last_run_at' => $lastRun?->toIso8601String(),
                    'ok' => $lastRun !== null && $lastRun->diffInSeconds(now(), true) <= $staleAfter,
                ],
                'invoicing' => ['problems' => $invoicingProblems, 'series' => $db ? $this->seriesUsage() : []],
                'build_sha' => config('product.build_sha'),
                'time' => now()->toIso8601String(),
            ];
        }

        return response()->json($payload, $db ? 200 : 503);
    }

    /** @return list<array{series: string, financial_year: string, used: int, capacity: int, percent: float, warning: bool}> */
    private function seriesUsage(): array
    {
        try {
            $capacity = InvoiceNumberAllocator::capacity();

            return DB::table('invoice_sequences')->where('financial_year', FinancialYear::label(now()))->get()->map(fn ($r) => [
                'series' => $r->series,
                'financial_year' => $r->financial_year,
                'used' => (int) $r->last_number,
                'capacity' => $capacity,
                'percent' => round($r->last_number / $capacity * 100, 1),
                'warning' => $r->last_number >= $capacity * InvoiceNumberAllocator::WARN_AT,
            ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function value(callable $probe): ?int
    {
        try {
            return (int) $probe();
        } catch (Throwable) {
            return null;
        }
    }
}
