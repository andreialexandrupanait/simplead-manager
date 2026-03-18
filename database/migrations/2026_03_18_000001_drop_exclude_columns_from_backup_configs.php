<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropColumn(['exclude_paths', 'exclude_tables']);
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->json('exclude_paths')->nullable();
            $table->json('exclude_tables')->nullable();
        });
    }
};
