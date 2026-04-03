<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('wp_admin_user_id')->nullable()->after('report_template_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('wp_admin_user_id')->references('id')->on('site_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['wp_admin_user_id']);
            $table->dropColumn('wp_admin_user_id');
        });
    }
};
