<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE backups ADD COLUMN IF NOT EXISTS local_export_status varchar(16) NULL');
        DB::statement('ALTER TABLE backups ADD COLUMN IF NOT EXISTS local_export_file_path varchar(512) NULL');
        DB::statement('ALTER TABLE backups ADD COLUMN IF NOT EXISTS local_export_file_size bigint NULL');
        DB::statement('ALTER TABLE backups ADD COLUMN IF NOT EXISTS local_export_error text NULL');
        DB::statement('ALTER TABLE backups ADD COLUMN IF NOT EXISTS local_exported_at timestamp NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS backups_site_id_local_export_status_idx ON backups (site_id, local_export_status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS backups_site_id_local_export_status_idx');
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS local_exported_at');
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS local_export_error');
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS local_export_file_size');
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS local_export_file_path');
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS local_export_status');
    }
};
