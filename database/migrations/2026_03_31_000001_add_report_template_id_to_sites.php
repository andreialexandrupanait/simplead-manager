<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('report_template_id')
                ->nullable()
                ->after('maintenance_plan_id')
                ->constrained('report_templates')
                ->nullOnDelete();
        });

        // Backfill from existing schedules (first schedule per site)
        DB::statement(<<<'SQL'
            UPDATE sites
            SET report_template_id = sub.report_template_id
            FROM (
                SELECT DISTINCT ON (site_id) site_id, report_template_id
                FROM report_schedules
                ORDER BY site_id, id
            ) sub
            WHERE sites.id = sub.site_id
              AND sites.report_template_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_template_id');
        });
    }
};
