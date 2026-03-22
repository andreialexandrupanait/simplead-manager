<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        return collect($indexes)->contains(fn ($idx) => $idx['name'] === $indexName);
    }

    public function up(): void
    {
        if (! Schema::hasColumn('analytics_connections', 'next_sync_at')) {
            Schema::table('analytics_connections', function (Blueprint $table) {
                $table->timestamp('next_sync_at')->nullable()->after('last_sync_at');
                $table->integer('interval_minutes')->default(1440)->after('next_sync_at');
            });
        }

        if (! Schema::hasColumn('search_console_connections', 'next_sync_at')) {
            Schema::table('search_console_connections', function (Blueprint $table) {
                $table->timestamp('next_sync_at')->nullable()->after('last_sync_at');
                $table->integer('interval_minutes')->default(1440)->after('next_sync_at');
            });
        }

        if (! Schema::hasColumn('site_cloudflare', 'is_active')) {
            Schema::table('site_cloudflare', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('connected_at');
                $table->timestamp('next_sync_at')->nullable()->after('is_active');
                $table->integer('interval_minutes')->default(360)->after('next_sync_at');
                $table->timestamp('last_sync_at')->nullable()->after('interval_minutes');
            });
        }

        if (! Schema::hasColumn('performance_monitors', 'interval_minutes')) {
            Schema::table('performance_monitors', function (Blueprint $table) {
                $table->integer('interval_minutes')->default(10080)->after('day_of_week');
            });
        }

        // Add composite indexes for dispatcher queries
        if (! $this->indexExists('analytics_connections', 'analytics_connections_is_active_next_sync_at_index')) {
            Schema::table('analytics_connections', function (Blueprint $table) {
                $table->index(['is_active', 'next_sync_at']);
            });
        }

        if (! $this->indexExists('search_console_connections', 'search_console_connections_is_active_next_sync_at_index')) {
            Schema::table('search_console_connections', function (Blueprint $table) {
                $table->index(['is_active', 'next_sync_at']);
            });
        }

        if (! $this->indexExists('site_cloudflare', 'site_cloudflare_is_active_next_sync_at_index')) {
            Schema::table('site_cloudflare', function (Blueprint $table) {
                $table->index(['is_active', 'next_sync_at']);
            });
        }

        if (! $this->indexExists('performance_monitors', 'performance_monitors_is_active_next_test_at_index')) {
            Schema::table('performance_monitors', function (Blueprint $table) {
                $table->index(['is_active', 'next_test_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('analytics_connections', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_sync_at']);
            $table->dropColumn(['next_sync_at', 'interval_minutes']);
        });

        Schema::table('search_console_connections', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_sync_at']);
            $table->dropColumn(['next_sync_at', 'interval_minutes']);
        });

        Schema::table('site_cloudflare', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_sync_at']);
            $table->dropColumn(['is_active', 'next_sync_at', 'interval_minutes', 'last_sync_at']);
        });

        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_test_at']);
            $table->dropColumn('interval_minutes');
        });
    }
};
