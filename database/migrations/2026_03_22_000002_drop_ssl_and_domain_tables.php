<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ssl_check_history');
        Schema::dropIfExists('ssl_certificates');
        Schema::dropIfExists('domain_check_history');
        Schema::dropIfExists('domain_monitors');

        if (Schema::hasColumn('sites', 'ssl_ok')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn(['ssl_ok', 'ssl_expiry']);
            });
        }

        if (Schema::hasColumn('uptime_monitors', 'check_ssl')) {
            Schema::table('uptime_monitors', function (Blueprint $table) {
                $table->dropColumn(['check_ssl', 'ssl_expiry_threshold']);
            });
        }
    }

    public function down(): void
    {
        // SSL monitoring feature has been permanently removed
    }
};
