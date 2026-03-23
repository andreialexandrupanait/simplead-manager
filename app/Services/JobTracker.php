<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class JobTracker
{
    protected const PREFIX = 'job-tracker:';

    protected const RUNNING_TTL = 3600;    // 1 hour

    protected const FINISHED_TTL = 300;    // 5 minutes

    public static function start(string $key, string $message = 'Starting...'): void
    {
        Cache::put(static::PREFIX.$key, [
            'status' => 'running',
            'progress' => 0,
            'message' => $message,
            'started_at' => now()->toISOString(),
        ], static::RUNNING_TTL);
    }

    public static function progress(string $key, int $percent, string $message = ''): void
    {
        $data = Cache::get(static::PREFIX.$key);
        if (! $data) {
            return;
        }

        $data['progress'] = min(100, max(0, $percent));
        if ($message) {
            $data['message'] = $message;
        }

        Cache::put(static::PREFIX.$key, $data, static::RUNNING_TTL);
    }

    public static function complete(string $key, string $message = 'Complete'): void
    {
        Cache::put(static::PREFIX.$key, [
            'status' => 'complete',
            'progress' => 100,
            'message' => $message,
            'started_at' => Cache::get(static::PREFIX.$key)['started_at'] ?? now()->toISOString(),
        ], static::FINISHED_TTL);
    }

    public static function fail(string $key, string $error = 'Job failed'): void
    {
        Cache::put(static::PREFIX.$key, [
            'status' => 'failed',
            'progress' => 0,
            'message' => $error,
            'started_at' => Cache::get(static::PREFIX.$key)['started_at'] ?? now()->toISOString(),
        ], static::FINISHED_TTL);
    }

    public static function get(string $key): ?array
    {
        return Cache::get(static::PREFIX.$key);
    }
}
