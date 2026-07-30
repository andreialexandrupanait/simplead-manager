<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 1.1: drop the duplicate SEO-audit engine. The engine (models
 * SeoAudit/SeoImage/SeoIssue/SeoKeywordRanking/SeoLink/SeoMonitor/SeoPage, its
 * services, jobs, dispatchers, Livewire screens and report section) was removed
 * in the same change — SEO audit lives in a separate application now
 * (SPEC-SAD-MANAGER §1). No kept table holds a foreign key INTO these; the only
 * in-app consumers (SiteRedirects broken-link suggestions, the report's
 * SeoGatherer) were unwired in the same commit.
 *
 * Non-transactional to match the other DDL migrations (runs on the direct,
 * non-PgBouncer connection at deploy — see deploy.sh). CASCADE only touches
 * this SEO set (they reference each other and `sites`, nothing references them).
 * Rollback is a deliberate no-op: these tables carry audit data with no value to
 * the maintenance product; recovery, if ever needed, is the mandatory pre-deploy
 * pg_dump (see docs/runbook-instalare.md §3b).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const SEO_TABLES = [
        'seo_links',
        'seo_images',
        'seo_issues',
        'seo_keyword_rankings',
        'seo_pages',
        'seo_audits',
        'seo_monitors',
    ];

    public function up(): void
    {
        foreach (self::SEO_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: the SEO engine and its data left the Manager in
        // Faza 1.1. See the class docblock for the recovery path.
    }
};
