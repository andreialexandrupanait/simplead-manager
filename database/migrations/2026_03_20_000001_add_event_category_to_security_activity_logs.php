<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_activity_logs', function (Blueprint $table) {
            $table->string('event_category', 20)->default('security');
            $table->index(['site_id', 'event_category']);
        });

        // Backfill existing rows
        DB::table('security_activity_logs')
            ->where(function ($q) {
                $q->where('event_type', 'like', 'backup_%')
                    ->orWhere('event_type', 'like', 'restore_%')
                    ->orWhere('event_type', 'like', 'direct_upload%');
            })
            ->update(['event_category' => 'backup']);

        DB::table('security_activity_logs')
            ->where(function ($q) {
                $q->where('event_type', 'like', 'plugin_%')
                    ->orWhere('event_type', 'like', 'theme_%');
            })
            ->where('event_category', 'security')
            ->update(['event_category' => 'plugin']);

        DB::table('security_activity_logs')
            ->where(function ($q) {
                $q->where('event_type', 'like', 'user_%')
                    ->orWhere('event_type', 'like', 'login_%')
                    ->orWhere('event_type', '=', 'auto_login');
            })
            ->where('event_category', 'security')
            ->update(['event_category' => 'user']);
    }

    public function down(): void
    {
        Schema::table('security_activity_logs', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'event_category']);
            $table->dropColumn('event_category');
        });
    }
};
