<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_connections', function (Blueprint $table) {
            $table->timestamp('next_sync_at')->nullable()->after('last_sync_at');
            $table->integer('interval_minutes')->default(1440)->after('next_sync_at'); // 24h
        });

        Schema::table('search_console_connections', function (Blueprint $table) {
            $table->timestamp('next_sync_at')->nullable()->after('last_sync_at');
            $table->integer('interval_minutes')->default(1440)->after('next_sync_at'); // 24h
        });

        Schema::table('site_cloudflare', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('connected_at');
            $table->timestamp('next_sync_at')->nullable()->after('is_active');
            $table->integer('interval_minutes')->default(360)->after('next_sync_at'); // 6h
            $table->timestamp('last_sync_at')->nullable()->after('interval_minutes');
        });

        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->integer('interval_minutes')->default(10080)->after('day_of_week'); // 7 days
        });

        // Add composite indexes for dispatcher queries
        Schema::table('analytics_connections', function (Blueprint $table) {
            $table->index(['is_active', 'next_sync_at']);
        });

        Schema::table('search_console_connections', function (Blueprint $table) {
            $table->index(['is_active', 'next_sync_at']);
        });

        Schema::table('site_cloudflare', function (Blueprint $table) {
            $table->index(['is_active', 'next_sync_at']);
        });

        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->index(['is_active', 'next_test_at']);
        });
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
