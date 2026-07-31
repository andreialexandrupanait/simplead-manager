<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

/**
 * How hard the backup is allowed to lean on a client's site.
 *
 * The profiles existed in config with six keys each and not one reader, so
 * `low_impact` and `fast` produced byte-identical behaviour — a setting that
 * described an intention the code never carried out. Every value here now
 * reaches something that honours it, and the keys that could not (a memory
 * budget the plugin overrides with its own ini_set; a file-count batch the
 * plugin has no parameter for) were removed rather than left as decoration.
 *
 * The mapping to what the plugin actually accepts:
 *   fileChunkBytes → /files/inventory `threshold`   (bin-packing budget per zip)
 *   compression    → /files/inventory `compression` (store = no CPU spent)
 *   dbTimeBudget   → /database/dump  `time_budget`  (soft wall-clock per call)
 *   dbSegmentBytes → /database/dump  `segment_bytes`
 *   dbBatchRows    → /database/dump  `batch_rows`
 *   pauseMs        → manager-side sleep between chunks
 *   minFreeDiskMb  → preflight refusal threshold
 */
final class ResourceProfile
{
    public function __construct(
        public readonly string $name,
        public readonly int $fileChunkBytes,
        public readonly string $compression,
        public readonly int $dbTimeBudget,
        public readonly int $dbSegmentBytes,
        public readonly int $dbBatchRows,
        public readonly int $pauseMs,
        public readonly int $minFreeDiskMb,
        public readonly int $maxConcurrency,
    ) {}

    public static function named(?string $name): self
    {
        $name = $name ?: (string) config('backup_v2.default_profile', 'low_impact');
        $profiles = (array) config('backup_v2.profiles', []);
        $values = (array) ($profiles[$name] ?? $profiles['low_impact'] ?? []);

        return new self(
            name: $name,
            fileChunkBytes: (int) ($values['file_chunk_mb'] ?? 25) * 1024 * 1024,
            compression: (string) ($values['compression'] ?? 'store'),
            dbTimeBudget: (int) ($values['db_time_budget'] ?? 20),
            dbSegmentBytes: (int) ($values['db_segment_mb'] ?? 8) * 1024 * 1024,
            dbBatchRows: (int) ($values['db_batch_rows'] ?? 200),
            pauseMs: (int) ($values['pause_ms'] ?? 400),
            minFreeDiskMb: (int) ($values['min_free_disk_mb'] ?? 512),
            maxConcurrency: (int) ($values['max_concurrency'] ?? 1),
        );
    }

    /**
     * Wait between chunks so the host gets its CPU and disk back.
     *
     * Crude on purpose. The site is shared with real visitors, and the cheapest
     * way to stay out of their way is to do less per unit time rather than to
     * try to be clever about when.
     */
    public function pause(): void
    {
        if ($this->pauseMs > 0) {
            usleep($this->pauseMs * 1000);
        }
    }
}
