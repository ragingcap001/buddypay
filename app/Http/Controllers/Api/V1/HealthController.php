<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/v1/health
     *
     * Distinguishes healthy / degraded / unhealthy:
     *  - unhealthy (503): database unreachable.
     *  - degraded (200):  an auxiliary dependency (redis/queue) is down.
     */
    public function show(): \Illuminate\Http\JsonResponse
    {
        $checks = [];
        $overall = 'healthy';

        // Database — authoritative; a failure makes the platform unhealthy.
        try {
            DB::select('select 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (Throwable $e) {
            $checks['database'] = ['status' => 'error'];
            $overall = 'unhealthy';
        }

        // Redis — auxiliary (cache/queue/locks); a failure degrades.
        $cacheDriver = (string) config('cache.default');

        if (in_array($cacheDriver, ['redis', 'redis-cluster'], true)) {
            try {
                app('redis')->ping();
                $checks['redis'] = ['status' => 'ok'];
            } catch (Throwable $e) {
                $checks['redis'] = ['status' => 'error'];
                $overall = $overall === 'unhealthy' ? 'unhealthy' : 'degraded';
            }
        }

        // Queue — verify the configured connection can be resolved.
        $queueDriver = (string) config('queue.default');

        try {
            app('queue')->connection($queueDriver);
            $checks['queue'] = ['status' => 'ok', 'driver' => $queueDriver];
        } catch (Throwable $e) {
            $checks['queue'] = ['status' => 'error', 'driver' => $queueDriver];
            $overall = $overall === 'unhealthy' ? 'unhealthy' : 'degraded';
            Log::warning("Health check: queue driver [{$queueDriver}] unavailable: {$e->getMessage()}");
        }

        return response()->json([
            'status' => $overall,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $overall === 'unhealthy' ? 503 : 200);
    }
}
