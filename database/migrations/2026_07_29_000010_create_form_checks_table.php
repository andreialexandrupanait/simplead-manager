<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 5.1 — contact-form deliverability self-test results.
 *
 * Records the outcome of the (owner-gated, on-demand) form test per site: which
 * form plugin was detected, whether it is in the suppression allowlist, and
 * whether a marked submit was made / suppression confirmed. Never written by a
 * scheduler — only by the on-demand ContactFormChecker service.
 *
 * Expand-only + idempotent (CREATE TABLE IF NOT EXISTS), non-transactional to
 * match the other DDL migrations that run on the direct connection at deploy.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS form_checks (
                id                    bigserial PRIMARY KEY,
                site_id               bigint NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                form_plugin           varchar(255),
                supported             boolean NOT NULL DEFAULT false,
                submitted_at          timestamptz,
                delivery_verified     boolean NOT NULL DEFAULT false,
                suppression_confirmed boolean NOT NULL DEFAULT false,
                status                varchar(255),
                checked_at            timestamptz,
                created_at            timestamptz,
                updated_at            timestamptz
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS form_checks_site_id_index ON form_checks (site_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS form_checks');
    }
};
