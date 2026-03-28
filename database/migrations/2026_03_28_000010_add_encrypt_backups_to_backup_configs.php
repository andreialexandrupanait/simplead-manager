<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->boolean('encrypt_backups')->default(false)->after('backup_before_updates');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropColumn('encrypt_backups');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};
