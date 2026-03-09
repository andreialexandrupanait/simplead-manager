<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('restore_status')->nullable()->after('last_restored_at');
            $table->string('restore_stage')->nullable()->after('restore_status');
            $table->unsignedTinyInteger('restore_progress_percent')->default(0)->after('restore_stage');
            $table->string('restore_progress_message')->nullable()->after('restore_progress_percent');
            $table->text('restore_error_message')->nullable()->after('restore_progress_message');
            $table->index(['site_id', 'restore_status']);
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'restore_status']);
            $table->dropColumn([
                'restore_status',
                'restore_stage',
                'restore_progress_percent',
                'restore_progress_message',
                'restore_error_message',
            ]);
        });
    }
};
