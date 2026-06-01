<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Remove duplicate schedules per (site_id, report_template_id), keeping the most recent (highest id).
        DB::statement(<<<'SQL'
            DELETE FROM report_schedules
            WHERE id NOT IN (
                SELECT max_id FROM (
                    SELECT MAX(id) AS max_id
                    FROM report_schedules
                    GROUP BY site_id, report_template_id
                ) AS keep
            )
        SQL);

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unique(['site_id', 'report_template_id'], 'report_schedules_site_template_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropUnique('report_schedules_site_template_unique');
        });
    }
};
