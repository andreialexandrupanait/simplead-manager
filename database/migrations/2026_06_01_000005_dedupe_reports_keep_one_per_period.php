<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // For each (site_id, period_start, period_end), keep the "winner" and delete the rest.
        // Winner selection priority:
        //   1. status='completed' over generating/failed
        //   2. was_sent=true over false
        //   3. highest id (most recent)
        // Related report_recommendations cascade automatically (FK ON DELETE CASCADE).
        $losers = DB::select(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    file_path,
                    ROW_NUMBER() OVER (
                        PARTITION BY site_id, period_start, period_end
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
            )
            SELECT id, file_path FROM ranked WHERE rn > 1
        SQL);

        if (empty($losers)) {
            Log::info('Reports dedup migration: no duplicates found, skipping');

            return;
        }

        $deletedFiles = 0;
        $skippedFiles = 0;
        $failedFiles = 0;
        $disk = Storage::disk('local');

        foreach ($losers as $row) {
            if (! $row->file_path) {
                continue;
            }
            try {
                if ($disk->exists($row->file_path)) {
                    $disk->delete($row->file_path);
                    $deletedFiles++;
                } else {
                    $skippedFiles++;
                }
            } catch (\Throwable $e) {
                $failedFiles++;
                Log::warning("Reports dedup: failed to delete file {$row->file_path}", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $ids = array_map(fn ($r) => $r->id, $losers);
        $deletedRows = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deletedRows += DB::table('reports')->whereIn('id', $chunk)->delete();
        }

        Log::info('Reports dedup migration completed', [
            'deleted_rows' => $deletedRows,
            'deleted_files' => $deletedFiles,
            'skipped_files_missing' => $skippedFiles,
            'failed_files' => $failedFiles,
        ]);
    }

    public function down(): void
    {
        // No-op: dedup is destructive and cannot be reversed.
    }
};
