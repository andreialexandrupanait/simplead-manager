<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-domain circuit-breaker bookkeeping.
 *
 * The site-level circuit breaker (circuit_state / is_monitoring_disabled) is a
 * connector-reachability signal: it should only ever be tripped by work that
 * actually talks to the site (WP sync, backup, security scan, uptime). Failures
 * from third-party integrations (Google Analytics / Search Console) and SEO
 * crawls are NOT reachability signals and must never disable a site's backups.
 * Those domains record here instead, isolated from the shared breaker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_health_state', function (Blueprint $table) {
            $table->jsonb('domain_breakers')->nullable()->after('is_monitoring_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('site_health_state', function (Blueprint $table) {
            $table->dropColumn('domain_breakers');
        });
    }
};
