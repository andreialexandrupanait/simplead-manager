<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->integer('broken_links_count')->default(0);
            $table->integer('broken_images_count')->default(0);
            $table->integer('total_images_count')->default(0);
            $table->integer('redirect_pages_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->dropColumn(['broken_links_count', 'broken_images_count', 'total_images_count', 'redirect_pages_count']);
        });
    }
};
