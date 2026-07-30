<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §11 / §12.2 — tasks pulled from app.simplead.ro, per site.
 *
 * Deliberately narrow: what the work is, who has it and where it stands. No
 * rates, no totals, no invoice state — SPEC §1 keeps money in SAD Hub, and a
 * column that does not exist cannot leak into a client report.
 *
 * `sites.external_project_id` links a site to its upstream project. It is filled
 * automatically by name matching (26 of 40 sites match on the domain alone) and
 * can be corrected by hand for the rest.
 *
 * Raw DDL + non-transactional so it runs on the direct connection at deploy —
 * Schema:: builders break under PgBouncer transaction pooling.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS external_project_id varchar(191)');

        DB::statement('
            CREATE TABLE IF NOT EXISTS site_tasks (
                id bigserial PRIMARY KEY,
                site_id bigint NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                external_id varchar(191) NOT NULL,
                name varchar(500) NOT NULL DEFAULT \'\',
                status varchar(50) NOT NULL DEFAULT \'unknown\',
                task_type varchar(50),
                assignee_name varchar(191),
                due_date timestamptz,
                started_at timestamptz,
                status_changed_at timestamptz,
                external_updated_at timestamptz,
                synced_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz
            )
        ');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS site_tasks_site_external_unique ON site_tasks (site_id, external_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS site_tasks_site_status_idx ON site_tasks (site_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS site_tasks');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS external_project_id');
    }
};
