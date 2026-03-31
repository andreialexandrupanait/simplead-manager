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
            $table->dropColumn(['client_name', 'client_logo_path']);
        });

        Schema::table('site_report_configs', function (Blueprint $table) {
            $table->dropColumn(['show_security', 'show_cloudflare', 'custom_recommendations']);
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->string('client_name')->nullable();
            $table->string('client_logo_path')->nullable();
        });

        Schema::table('site_report_configs', function (Blueprint $table) {
            $table->boolean('show_security')->default(true);
            $table->boolean('show_cloudflare')->default(false);
            $table->jsonb('custom_recommendations')->nullable();
        });
    }
};
