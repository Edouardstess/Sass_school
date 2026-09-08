<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Liveness and readiness.
 *
 * The distinction matters to an orchestrator: `/health` says "this process is
 * running, do not restart me" and must never touch a dependency, or a slow
 * database would trigger a restart loop. `/ready` says "I can serve traffic"
 * and does check dependencies, so a pod with a broken database is taken out of
 * the load balancer instead of failing requests.
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'service' => config('app.name'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                Cache::put('health:probe', 1, 5);
                Cache::get('health:probe');
            }),
            'storage' => $this->check(fn () => Storage::disk(config('schoolflow.storage.disk'))->exists('.')),
            'queue' => $this->check(fn () => DB::table('jobs')->count()),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return ApiResponse::success(
            ['status' => $healthy ? 'ready' : 'degraded', 'checks' => $checks],
            null,
            $healthy ? 200 : 503,
        );
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (Throwable $e) {
            // The message is useful to an operator reading the endpoint, and
            // /ready is not exposed publicly in production deployments.
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
