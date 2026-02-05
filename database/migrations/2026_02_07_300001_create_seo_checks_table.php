<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('homepage_title')->nullable();
            $table->text('homepage_meta_description')->nullable();
            $table->boolean('has_sitemap')->default(false);
            $table->string('sitemap_url')->nullable();
            $table->integer('sitemap_pages_count')->nullable();
            $table->boolean('has_robots_txt')->default(false);
            $table->json('robots_txt_issues')->nullable();
            $table->boolean('has_og_tags')->default(false);
            $table->boolean('has_twitter_cards')->default(false);
            $table->boolean('has_schema_markup')->default(false);
            $table->boolean('has_canonical')->default(false);
            $table->boolean('has_h1')->default(false);
            $table->boolean('heading_hierarchy_ok')->default(false);
            $table->json('indexability_issues')->nullable();
            $table->integer('score')->default(0);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_checks');
    }
};
