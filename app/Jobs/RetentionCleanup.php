<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ActivityLogger;
use App\Services\JobTracker;
use App\Services\RetentionPolicyService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RetentionCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public array $backoff = [120];

    public const JOB_KEY = 'retention-cleanup';

    public function __construct(
        public string $trigger = 'scheduled',
    ) {}

    public function handle(RetentionPolicyService $policy, SettingsService $settings): void
    {
        if (! $policy->isEnabled()) {
            Log::info('Retention cleanup skipped: disabled');

            return;
        }

        JobTracker::start(self::JOB_KEY, 'Starting retention cleanup...');
        $startTime = microtime(true);
        $deadline = now()->addMinutes(12);
        $hitDeadline = false;

        $categories = RetentionPolicyService::CATEGORIES;
        $categoryKeys = array_keys($categories);
        $totalCategories = count($categoryKeys);
        $categoryResults = [];

        foreach ($categoryKeys as $index => $categoryKey) {
            if (now()->gte($deadline)) {
                $hitDeadline = true;
                Log::warning("Retention cleanup hit deadline at category: {$categoryKey}");
                break;
            }

            $config = $categories[$categoryKey];
            $days = $this->trigger === 'manual'
                ? $policy->getDaysFresh($categoryKey)
                : $policy->getDays($categoryKey);

            $cutoff = now()->subDays($days);
            $categoryDeleted = 0;

            $progress = (int) round(($index / $totalCategories) * 100);
            JobTracker::progress(self::JOB_KEY, $progress, "Cleaning {$config['label']}...");

            foreach ($config['tables'] as $tableConfig) {
                if (now()->gte($deadline)) {
                    $hitDeadline = true;
                    break;
                }

                // Isolate each table: a single bad table (e.g. one dropped by a
                // migration but still lingering in a stale config) must never
                // abort the whole nightly run — the later categories still need
                // to prune. Log and continue instead of throwing.
                try {
                    if (! Schema::hasTable($tableConfig['table'])) {
                        Log::warning("Retention cleanup skipped missing table: {$tableConfig['table']}");

                        continue;
                    }

                    $deleted = $this->deleteInBatches(
                        table: $tableConfig['table'],
                        column: $tableConfig['column'],
                        colType: $tableConfig['col_type'],
                        cutoff: $cutoff,
                        condition: $tableConfig['condition'],
                        deadline: $deadline,
                    );

                    $categoryDeleted += $deleted;
                } catch (\Throwable $e) {
                    Log::error("Retention cleanup failed for table {$tableConfig['table']}", [
                        'category' => $categoryKey,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $categoryResults[$categoryKey] = [
                'days' => $days,
                'deleted' => $categoryDeleted,
            ];
        }

        // Expired backups (per-backup expires_at)
        $expiredBackups = $this->cleanExpiredBackups($deadline);

        // Expired rollback points
        try {
            app(\App\Services\RollbackService::class)->cleanExpired();
        } catch (\Exception $e) {
            // Ignore if service doesn't exist
        }

        $duration = (int) round(microtime(true) - $startTime);
        $totalDeleted = array_sum(array_column($categoryResults, 'deleted'));

        $result = [
            'trigger' => $this->trigger,
            'duration_seconds' => $duration,
            'total_deleted' => $totalDeleted,
            'categories' => $categoryResults,
            'expired_backups' => $expiredBackups,
            'hit_deadline' => $hitDeadline,
        ];

        // Persist result to app_settings
        $settings->set('retention_last_run_at', now()->toISOString(), 'retention', 'string');
        $settings->set('retention_last_run_result', $result, 'retention', 'json');

        // Activity log
        ActivityLogger::retentionCleanupCompleted($result, $this->trigger);

        $message = "Retention cleanup complete: {$totalDeleted} records cleaned in {$duration}s";
        if ($hitDeadline) {
            $message .= ' (hit deadline)';
            Log::warning($message, $result);
        } else {
            Log::info($message, $result);
        }

        JobTracker::complete(self::JOB_KEY, "Cleaned {$totalDeleted} records in {$duration}s");
    }

    private function deleteInBatches(
        string $table,
        string $column,
        string $colType,
        Carbon $cutoff,
        ?array $condition,
        Carbon $deadline,
        int $batchSize = 5000,
    ): int {
        $total = 0;

        $cutoffValue = $colType === 'date'
            ? $cutoff->toDateString()
            : $cutoff->toDateTimeString();

        do {
            if (now()->gte($deadline)) {
                break;
            }

            $conditionSQL = '';
            $params = [$cutoffValue];

            if ($condition) {
                if ($condition[1] === 'in') {
                    $placeholders = implode(',', array_fill(0, count($condition[2]), '?'));
                    $conditionSQL = "AND \"{$condition[0]}\" IN ({$placeholders})";
                    $params = array_merge($params, $condition[2]);
                } else {
                    $conditionSQL = "AND \"{$condition[0]}\" {$condition[1]} ?";
                    $params[] = $condition[2];
                }
            }

            $deleted = DB::affectingStatement(
                "DELETE FROM \"{$table}\" WHERE ctid IN (
                    SELECT ctid FROM \"{$table}\"
                    WHERE \"{$column}\" <= ?
                    {$conditionSQL}
                    ORDER BY \"{$column}\" ASC
                    LIMIT {$batchSize}
                )",
                $params
            );

            $total += $deleted;

            if ($deleted >= $batchSize) {
                usleep(100_000); // 100ms pause
            }
        } while ($deleted >= $batchSize);

        return $total;
    }

    private function cleanExpiredBackups(Carbon $deadline): int
    {
        $expiredBackups = DB::table('backups')
            ->where('expires_at', '<=', now())
            ->where('is_locked', false)
            ->cursor();

        $count = 0;

        foreach ($expiredBackups as $backup) {
            if (now()->gte($deadline)) {
                break;
            }

            try {
                if ($backup->storage_destination_id && $backup->file_path) {
                    $destination = \App\Models\StorageDestination::find($backup->storage_destination_id);
                    if ($destination) {
                        $driver = \App\Services\Backup\Storage\StorageFactory::make($destination);
                        $driver->delete($backup->file_path);
                        $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                    }
                }
                DB::table('backups')->where('id', $backup->id)->delete();
                $count++;
            } catch (\Exception $e) {
                Log::warning("Failed to clean expired backup {$backup->id}", [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
            }
        }

        return $count;
    }

    public function failed(\Throwable $exception): void
    {
        $exceptionClass = get_class($exception);
        JobTracker::fail(self::JOB_KEY, "Retention cleanup failed: {$exceptionClass}");
        Log::error('Retention cleanup failed', [
            'exception' => $exceptionClass,
            'code' => $exception->getCode(),
        ]);
    }
}
