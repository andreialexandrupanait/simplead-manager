<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_backup_id')->nullable();
            $table->string('manifest_path')->nullable();
            $table->unsignedInteger('files_changed_count')->nullable();
            $table->unsignedInteger('files_deleted_count')->nullable();
            $table->unsignedInteger('files_total_count')->nullable();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->foreign('parent_backup_id')
                ->references('id')->on('backups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['parent_backup_id']);
            $table->dropColumn([
                'parent_backup_id',
                'manifest_path',
                'files_changed_count',
                'files_deleted_count',
                'files_total_count',
            ]);
        });
    }
};
