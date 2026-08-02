<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which version of the backup engine each site is running.
 *
 * The fleet console has to show, for every site, whether it is ready to be migrated — and asking
 * twenty-nine WordPress installations over HTTP on every page render is not a table, it is a
 * minute of waiting and twenty-nine requests to other people's servers. The connector's version is
 * already kept this way (`sites.connector_version`); this is the same idea for the plugin that
 * actually takes the backups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('backup_plugin_version')->nullable()->after('connector_version');
            // When it was last confirmed, so a stale reading can be told apart from a fresh one.
            $table->timestamp('backup_plugin_checked_at')->nullable()->after('backup_plugin_version');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['backup_plugin_version', 'backup_plugin_checked_at']);
        });
    }
};
