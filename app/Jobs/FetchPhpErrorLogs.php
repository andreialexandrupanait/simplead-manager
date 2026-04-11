<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPhpErrorLogs implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'fetch-php-errors-' . $this->site->id;
    }

    public function handle(): void
    {
        if (! $this->site->is_connected) {
            return;
        }

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $result = $api->getErrorLogs(200);
            $entries = $result['entries'] ?? [];

            $newFatals = 0;

            foreach ($entries as $entry) {
                $hash = md5(($entry['level'] ?? '') . ($entry['message'] ?? ''));

                $existing = PhpErrorLog::where('site_id', $this->site->id)
                    ->where('message_hash', $hash)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'count' => $existing->count + ($entry['count'] ?? 1),
                        'last_seen_at' => $entry['last_seen'] ?? now(),
                        'is_resolved' => false,
                    ]);
                } else {
                    PhpErrorLog::create([
                        'site_id' => $this->site->id,
                        'level' => $entry['level'] ?? 'unknown',
                        'message' => mb_substr($entry['message'] ?? '', 0, 2000),
                        'file' => $entry['file'] ?? null,
                        'line' => $entry['line'] ?? null,
                        'message_hash' => $hash,
                        'count' => $entry['count'] ?? 1,
                        'first_seen_at' => $entry['timestamp'] ?? now(),
                        'last_seen_at' => $entry['last_seen'] ?? $entry['timestamp'] ?? now(),
                    ]);

                    if (($entry['level'] ?? '') === 'fatal') {
                        $newFatals++;
                    }
                }
            }

            if ($newFatals > 0) {
                NotificationService::notifySiteEvent(
                    $this->site,
                    'php_fatal_error',
                    'New PHP Fatal Error(s)',
                    "{$newFatals} new fatal error(s) detected on {$this->site->name}.",
                    ['Site' => $this->site->name, 'New Fatal Errors' => $newFatals],
                    'critical'
                );

                ActivityLogger::log(
                    type: 'error_log',
                    severity: 'critical',
                    title: "{$newFatals} new PHP fatal error(s) detected",
                    site: $this->site,
                    icon: 'alert-triangle',
                );
            }
        } catch (\Throwable $e) {
            Log::warning("PHP error log fetch failed for site {$this->site->name}: {$e->getMessage()}");
        }
    }
}
