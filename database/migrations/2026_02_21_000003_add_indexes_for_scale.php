<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // performance_tests: ReportGeneratorService queries by site + latest date
        if (Schema::hasTable('performance_tests') && ! $this->indexExists('performance_tests', 'idx_performance_tests_site_tested_at')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->index(['site_id', 'tested_at'], 'idx_performance_tests_site_tested_at');
            });
        }

        // reports: listing filters by site + status
        if (Schema::hasTable('reports') && ! $this->indexExists('reports', 'idx_reports_site_status')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->index(['site_id', 'status'], 'idx_reports_site_status');
            });
        }

        // reports: schedule detail pages list reports by schedule
        if (Schema::hasTable('reports') && ! $this->indexExists('reports', 'idx_reports_schedule_created')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->index(['report_schedule_id', 'created_at'], 'idx_reports_schedule_created');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('performance_tests')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->dropIndex('idx_performance_tests_site_tested_at');
            });
        }

        if (Schema::hasTable('reports')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropIndex('idx_reports_site_status');
                $table->dropIndex('idx_reports_schedule_created');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        return collect($indexes)->contains(fn ($idx) => $idx['name'] === $indexName);
    }
};
