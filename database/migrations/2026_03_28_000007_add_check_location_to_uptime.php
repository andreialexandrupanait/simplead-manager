<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add location tracking to uptime checks
        Schema::table('uptime_checks', function (Blueprint $table) {
            $table->string('location', 50)->default('primary')->after('status_code');
        });

        // Add multi-location config to uptime monitors
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->jsonb('check_locations')->nullable()->after('maintenance_reason');
            $table->boolean('require_all_locations_down')->default(false)->after('check_locations');
        });
    }

    public function down(): void
    {
        Schema::table('uptime_checks', function (Blueprint $table) {
            $table->dropColumn('location');
        });

        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->dropColumn(['check_locations', 'require_all_locations_down']);
        });
    }
};
