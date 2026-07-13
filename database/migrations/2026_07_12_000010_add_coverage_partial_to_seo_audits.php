<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3-20: surface partial crawl coverage instead of silently truncating.
 *
 * The crawler stops at `default_max_pages` (or the runtime deadline). When it
 * exits with URLs still queued, the audit previously looked complete — a
 * consumer could not tell a fully-crawled site from one capped at 500 pages.
 * This flag records that the crawl did not cover every discovered page.
 *
 * Expand-only + idempotent (IF NOT EXISTS). Non-transactional so it runs cleanly
 * on the direct (non-PgBouncer) connection used by deploy.sh.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE seo_audits ADD COLUMN IF NOT EXISTS coverage_partial boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE seo_audits DROP COLUMN IF EXISTS coverage_partial');
    }
};
