<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember how long this site's database actually needs to dump.
 *
 * The dump has to finish inside one request — the consistency guarantee is one transaction on one
 * connection — so the time budget is a hard edge, not a knob. The `low_impact` profile allows 20
 * seconds, which is right for most sites and hopeless for a large WooCommerce: the same backup
 * fails every night, at the same point, with an error about consistency that reads like corruption.
 *
 * The runner escalates the budget when a dump runs out of time and writes the value that worked
 * here, so tomorrow starts from what we learned rather than paying for the discovery again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_configs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('db_time_budget_seconds')->nullable()->after('backup_engine');
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table): void {
            $table->dropColumn('db_time_budget_seconds');
        });
    }
};
