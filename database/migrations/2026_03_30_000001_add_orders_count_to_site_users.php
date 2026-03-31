<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->unsignedInteger('orders_count')->default(0)->after('posts_count');
        });
    }

    public function down(): void
    {
        Schema::table('site_users', function (Blueprint $table) {
            $table->dropColumn('orders_count');
        });
    }
};
