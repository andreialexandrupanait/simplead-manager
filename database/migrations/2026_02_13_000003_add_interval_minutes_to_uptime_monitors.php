<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->integer('interval_minutes')->default(5)->after('interval');
        });

        // Backfill from existing interval column (seconds → minutes)
        if (DB::getDriverName() === 'sqlite') {
            DB::table('uptime_monitors')
                ->whereNotNull('interval')
                ->where('interval', '>', 0)
                ->update(['interval_minutes' => DB::raw('MAX(ROUND(interval / 60), 1)')]);
        } else {
            DB::table('uptime_monitors')
                ->whereNotNull('interval')
                ->where('interval', '>', 0)
                ->update(['interval_minutes' => DB::raw('GREATEST(ROUND(interval / 60), 1)')]);
        }
    }

    public function down(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->dropColumn('interval_minutes');
        });
    }
};
