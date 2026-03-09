<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_monthly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // Uptime metrics
            $table->decimal('uptime_avg_response_ms', 10, 2)->nullable();
            $table->decimal('uptime_percentage', 6, 3)->nullable();
            $table->integer('uptime_down_checks')->nullable();
            $table->integer('uptime_incidents_count')->nullable();

            // Backup metrics
            $table->integer('backups_total')->nullable();
            $table->integer('backups_successful')->nullable();
            $table->integer('backups_failed')->nullable();

            // Updates metrics
            $table->integer('updates_applied')->nullable();

            // Security metrics
            $table->decimal('security_avg_score', 5, 2)->nullable();

            // Performance metrics (desktop + mobile)
            $table->decimal('performance_avg_desktop', 5, 2)->nullable();
            $table->decimal('performance_avg_mobile', 5, 2)->nullable();

            // Analytics metrics (from GA4 cache)
            $table->integer('analytics_users')->nullable();
            $table->integer('analytics_sessions')->nullable();
            $table->integer('analytics_pageviews')->nullable();

            // Search Console metrics
            $table->integer('search_console_clicks')->nullable();
            $table->integer('search_console_impressions')->nullable();
            $table->decimal('search_console_avg_position', 6, 2)->nullable();

            // Cloudflare metrics
            $table->bigInteger('cloudflare_requests')->nullable();
            $table->bigInteger('cloudflare_bandwidth_bytes')->nullable();
            $table->decimal('cloudflare_cache_hit_ratio', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_monthly_snapshots');
    }
};
