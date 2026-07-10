<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in per-site flag. When enabled, plugin updates run through the safe
 * pipeline (pre-update backup → update → health check → visual regression →
 * auto-rollback on failure) instead of the inline update. Default false so
 * existing behaviour is unchanged until a site explicitly opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('safe_updates_enabled')->default(false)->after('health_score');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('safe_updates_enabled');
        });
    }
};
