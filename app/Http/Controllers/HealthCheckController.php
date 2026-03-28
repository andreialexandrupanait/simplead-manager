<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'horizon' => $this->checkHorizon(),
            'disk' => $this->checkDisk(),
        ];

        $hasFailure = collect($checks)->contains('status', 'fail');
        $hasDegraded = collect($checks)->contains('status', 'degraded');

        $status = $hasFailure ? 'fail' : ($hasDegraded ? 'degraded' : 'ok');

        return response()->json([
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $status === 'fail' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Database connection failed'];
        }
    }

    private function checkRedis(): array
    {
        try {
            Cache::store('redis')->put('health_check', true, 10);
            Cache::store('redis')->forget('health_check');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Redis connection failed'];
        }
    }

    private function checkHorizon(): array
    {
        try {
            $supervisors = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();

            if (empty($supervisors)) {
                return ['status' => 'degraded', 'message' => 'Horizon is not running'];
            }

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'degraded', 'message' => 'Could not check Horizon status'];
        }
    }

    private function checkDisk(): array
    {
        $free = disk_free_space('/');
        $total = disk_total_space('/');

        if ($total === false || $total == 0) {
            return ['status' => 'fail', 'message' => 'Cannot read disk space'];
        }

        $percentFree = round(($free / $total) * 100, 1);

        if ($percentFree < 5) {
            return ['status' => 'fail', 'message' => 'Critically low disk space'];
        }

        if ($percentFree < 10) {
            return ['status' => 'degraded', 'message' => 'Low disk space'];
        }

        return ['status' => 'ok'];
    }
}
