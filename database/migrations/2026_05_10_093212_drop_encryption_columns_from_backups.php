<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });

        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropColumn('encrypt_backups');
        });

        Schema::table('app_backup_configs', function (Blueprint $table) {
            $table->dropColumn(['encrypt_backup', 'encryption_password']);
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('is_locked');
        });

        Schema::table('backup_configs', function (Blueprint $table) {
            $table->boolean('encrypt_backups')->default(false)->after('backup_before_updates');
        });

        Schema::table('app_backup_configs', function (Blueprint $table) {
            $table->boolean('encrypt_backup')->default(false);
            $table->text('encryption_password')->nullable();
        });
    }
};
