<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->smallInteger('consecutive_failures')->default(0);
        });

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->text('last_failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropColumn('last_failure_reason');
        });
    }
};
