<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_monitors', function (Blueprint $table) {
            $table->boolean('crawl_enabled')->default(false);
            $table->integer('crawl_interval_days')->default(7);
            $table->timestamp('next_crawl_at')->nullable();
            $table->timestamp('last_crawl_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seo_monitors', function (Blueprint $table) {
            $table->dropColumn(['crawl_enabled', 'crawl_interval_days', 'next_crawl_at', 'last_crawl_at']);
        });
    }
};
