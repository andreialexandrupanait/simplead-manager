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
            $table->string('stage')->nullable()->after('status');
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('stage');
            $table->string('progress_message')->nullable()->after('progress_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['stage', 'progress_percent', 'progress_message']);
        });
    }
};
