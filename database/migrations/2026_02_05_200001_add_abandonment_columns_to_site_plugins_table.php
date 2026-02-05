<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_plugins', function (Blueprint $table) {
            $table->timestamp('wp_org_last_updated')->nullable()->after('auto_update');
            $table->boolean('is_on_wp_org')->nullable()->after('wp_org_last_updated');
            $table->boolean('is_abandoned')->default(false)->after('is_on_wp_org');
            $table->boolean('is_closed')->default(false)->after('is_abandoned');
            $table->string('closed_reason')->nullable()->after('is_closed');
            $table->timestamp('abandoned_checked_at')->nullable()->after('closed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('site_plugins', function (Blueprint $table) {
            $table->dropColumn([
                'wp_org_last_updated',
                'is_on_wp_org',
                'is_abandoned',
                'is_closed',
                'closed_reason',
                'abandoned_checked_at',
            ]);
        });
    }
};
