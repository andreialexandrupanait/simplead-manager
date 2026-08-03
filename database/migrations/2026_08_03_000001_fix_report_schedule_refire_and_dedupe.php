<?php

declare(strict_types=1);

use App\Models\ReportSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Repair after the 2026-08-01 triple-report incident.
 *
 * calculateNextRun() returned UTC while datetimes are persisted naive in the app
 * timezone, so every schedule fired 3h early, stayed due until the real hour, and
 * the dispatcher re-claimed it each 5-minute tick — GenerateReport's 1-hour dedup
 * window then minted (and emailed) a fresh duplicate every ~65 minutes. A blade
 * crash on the Cloudflare section additionally pushed 8 schedules to 3 consecutive
 * failures and auto-deactivated them.
 *
 * This migration (1) deletes the duplicate scheduled reports, keeping the same
 * winner the 2026-06-01 dedup chose (completed > was_sent > newest), (2)
 * reactivates the schedules the blade crash deactivated, (3) recomputes every
 * active schedule's next_run_at with the fixed timezone logic, and (4) adds a
 * partial unique index so a re-fired schedule can never insert a second live
 * report for the same period again.
 *
 * Raw non-transactional DDL (PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // (1) Dedupe scheduled reports: one live row per (schedule, period).
        $losers = DB::select(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    file_path,
                    ROW_NUMBER() OVER (
                        PARTITION BY report_schedule_id, period_start, period_end
                        ORDER BY
                            CASE status
                                WHEN 'completed' THEN 0
                                WHEN 'generating' THEN 1
                                WHEN 'failed' THEN 2
                                ELSE 3
                            END,
                            CASE WHEN was_sent THEN 0 ELSE 1 END,
                            id DESC
                    ) AS rn
                FROM reports
                WHERE report_schedule_id IS NOT NULL AND status <> 'failed'
            )
            SELECT id, file_path FROM ranked WHERE rn > 1
        SQL);

        $disk = Storage::disk('local');
        foreach ($losers as $row) {
            if (! $row->file_path) {
                continue;
            }
            try {
                if ($disk->exists($row->file_path)) {
                    $disk->delete($row->file_path);
                }
            } catch (\Throwable $e) {
                Log::warning("Report refire dedup: failed to delete file {$row->file_path}", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $ids = array_map(fn ($r) => $r->id, $losers);
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('reports')->whereIn('id', $chunk)->delete();
        }

        // (2) Reactivate the schedules the Cloudflare blade crash deactivated —
        // the failures were the manager's own bug, not the sites'.
        $reactivated = DB::table('report_schedules')
            ->where('is_active', false)
            ->where('consecutive_failures', '>=', 3)
            ->where('last_failure_reason', 'ILIKE', '%total_requests_formatted%')
            ->update([
                'is_active' => true,
                'consecutive_failures' => 0,
                'last_failure_reason' => null,
            ]);

        // (3) Recompute next_run_at with the fixed timezone conversion; the stored
        // values are all 3h early.
        ReportSchedule::where('is_active', true)->each(function (ReportSchedule $schedule): void {
            $schedule->update(['next_run_at' => $schedule->calculateNextRun()]);
        });

        // (4) Belt and braces: the DB itself now refuses a second live report for
        // the same schedule+period. 'failed' rows stay out so a retry after a
        // genuine failure can still insert its fresh attempt.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS reports_schedule_period_live_unique
            ON reports (report_schedule_id, period_start, period_end)
            WHERE report_schedule_id IS NOT NULL AND status <> 'failed'
        SQL);

        Log::info('Report refire repair migration completed', [
            'duplicate_reports_deleted' => count($ids),
            'schedules_reactivated' => $reactivated,
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reports_schedule_period_live_unique');
        // The dedupe and schedule repair are data fixes and cannot be reversed.
    }
};
