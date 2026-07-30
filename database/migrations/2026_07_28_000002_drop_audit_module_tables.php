<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 1.2: drop the SEO/CRO audit module. The module (models Audit, AuditCard,
 * AuditCheck, AuditCheckResult, AuditReport, AuditRun, Prospect + its services,
 * DTOs, exceptions, jobs, Livewire screens, public report controller, config and
 * AuditConfigServiceProvider) was removed in the same change — audit lives in a
 * separate application now (SPEC-SAD-MANAGER §1: "Manager operează și măsoară.
 * Nu analizează niciodată").
 *
 * The create migration 2026_07_23_000001 still runs its embedded
 * AuditChecksSeeder on a fresh migrate (that seeder was rewritten to the query
 * builder so it no longer needs the deleted model); this migration drops the
 * now-empty-of-consumers tables afterwards. The `sites.is_prospect` column and
 * the `withoutProspects` scope stay — the column is still queried and dropping a
 * column from the kept `sites` table is out of scope here.
 *
 * Non-transactional to match the other DDL migrations (direct, non-PgBouncer
 * connection at deploy). CASCADE covers the intra-module FKs (audit_check_results
 * → audit_checks/audits, audit_cards/audit_reports/audit_runs → audits, audits →
 * prospects); no kept table holds a foreign key INTO this set. Rollback is a
 * no-op — recovery is the mandatory pre-deploy pg_dump (docs/runbook-instalare.md).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const AUDIT_TABLES = [
        'audit_check_results',
        'audit_cards',
        'audit_reports',
        'audit_runs',
        'audit_checks',
        'audits',
        'prospects',
    ];

    public function up(): void
    {
        foreach (self::AUDIT_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: the audit module and its data left the Manager in
        // Faza 1.2. See the class docblock for the recovery path.
    }
};
