<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('last_login_at');
            $table->timestamp('synced_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'synced_at']);
        });
    }
};
