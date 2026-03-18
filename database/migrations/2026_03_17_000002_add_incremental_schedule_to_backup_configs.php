<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->string('incremental_frequency')->nullable();
            $table->tinyInteger('full_backup_day_of_week')->nullable();
            $table->timestamp('last_full_backup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropColumn([
                'incremental_frequency',
                'full_backup_day_of_week',
                'last_full_backup_at',
            ]);
        });
    }
};
