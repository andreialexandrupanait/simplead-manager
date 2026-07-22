<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C-04: drop legacy SEO/keyword tables left behind by an abandoned crawler/
 * keyword-research design. Verified at HEAD 0351c29: none of these has an
 * Eloquent model, any query/DB::table() reference in app code, or an incoming
 * foreign key from a kept table. Real keyword tracking lives in
 * seo_keyword_rankings (is_tracked/keyword_hash), not tracked_keywords.
 *
 * `keyword_positions` had a single reference — a retention-cleanup entry in
 * RetentionPolicyService — removed in the same change; that service already
 * guards each table with Schema::hasTable(), so the sweep never errored.
 *
 * CASCADE only touches this orphan set (no kept table references them). Runs on
 * the pgsql_direct connection at deploy (see deploy.sh); non-transactional to
 * match the other DDL migrations. Rollback is a no-op — these tables carry no
 * code and no data worth reconstructing; recovery, if ever needed, is the
 * mandatory pre-deploy pg_dump (see docs/runbook-instalare.md §3b).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const ORPHAN_TABLES = [
        'seo_content_revisions',
        'seo_contents',
        'seo_alert_rules',
        'backlink_snapshots',
        'backlinks',
        'competitor_keyword_positions',
        'keyword_positions',
        'keyword_page_mappings',
        'keyword_research_results',
        'tracked_keywords',
        'crawled_pages',
        'site_crawls',
    ];

    public function up(): void
    {
        foreach (self::ORPHAN_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: these are dead tables with no model, no code, and
        // no data of value. Reconstructing their exact original DDL would add
        // risk with no benefit. See the class docblock for the recovery path.
    }
};
