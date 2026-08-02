<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the backup engine did not answer, next to when we last asked.
 *
 * The console recorded a version number and treated it as proof the site was ready. It is not: a
 * version is what an install told us, and thirteen sites had the engine installed and unreachable —
 * blocked by the connector's own REST hardening, answering 401 to the manager that installed it,
 * while the console showed them green.
 *
 * So the reading now carries its failure. A row that says "not answering" without saying why is a
 * question nobody can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->text('backup_plugin_error')->nullable()->after('backup_plugin_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('backup_plugin_error');
        });
    }
};
