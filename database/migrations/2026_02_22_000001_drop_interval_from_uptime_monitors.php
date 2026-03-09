<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            if (Schema::hasColumn('uptime_monitors', 'interval')) {
                $table->dropColumn('interval');
            }
        });
    }

    public function down(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->integer('interval')->default(300)->after('url');
        });
    }
};
