<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicates keeping the latest record per (site_crawl_id, url)
        DB::statement("
            DELETE FROM crawled_pages
            WHERE id NOT IN (
                SELECT MAX(id)
                FROM crawled_pages
                GROUP BY site_crawl_id, url
            )
        ");

        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->unique(['site_crawl_id', 'url'], 'crawled_pages_crawl_url_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropUnique('crawled_pages_crawl_url_unique');
        });
    }
};
