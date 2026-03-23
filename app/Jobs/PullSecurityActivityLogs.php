<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Services\SecurityActivityService;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullSecurityActivityLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('security');
    }

    public function handle(): void
    {
        // Determine the last log timestamp for this site
        $lastLog = SecurityActivityLog::where('site_id', $this->site->id)
            ->orderByDesc('occurred_at')
            ->first();

        $since = $lastLog?->occurred_at?->toIso8601String();

        try {
            $api = new WordPressApiService($this->site);
            $response = $api->request('GET', '/audit-logs', [], $since ? ['since' => $since] : []);

            if (! $response->successful()) {
                Log::warning('PullSecurityActivityLogs: API error', [
                    'site_id' => $this->site->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            $data = $response->json();
            $wpLogs = $data['logs'] ?? [];
        } catch (\Exception $e) {
            Log::warning('PullSecurityActivityLogs: failed to fetch logs', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (empty($wpLogs)) {
            return;
        }

        // Map WordPress audit schema to SecurityActivityLog format
        $mappedLogs = array_map(function ($log) {
            return [
                'event_type' => $log['action'] ?? $log['event_type'] ?? 'unknown',
                'username' => $log['user_login'] ?? $log['username'] ?? null,
                'object_type' => $log['object_type'] ?? null,
                'object_name' => $log['object_name'] ?? $log['description'] ?? null,
                'action' => $log['action'] ?? null,
                'ip_address' => $log['user_ip'] ?? $log['ip_address'] ?? null,
                'user_agent' => $log['user_agent'] ?? null,
                'details' => $log['details'] ?? null,
                'occurred_at' => $log['created_at'] ?? $log['occurred_at'] ?? now()->toIso8601String(),
            ];
        }, $wpLogs);

        $ingested = app(SecurityActivityService::class)->ingestLogs($this->site, $mappedLogs);

        if ($ingested > 0) {
            Log::info('PullSecurityActivityLogs: ingested logs', [
                'site_id' => $this->site->id,
                'count' => $ingested,
            ]);
        }
    }
}
