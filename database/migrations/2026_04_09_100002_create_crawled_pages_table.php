<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_crawl_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->smallInteger('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->integer('content_length')->nullable();
            $table->integer('depth')->default(0);

            // Meta
            $table->text('title')->nullable();
            $table->smallInteger('title_length')->default(0);
            $table->text('meta_description')->nullable();
            $table->smallInteger('meta_desc_length')->default(0);
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('canonical_self_ref')->default(false);
            $table->string('meta_robots')->nullable();
            $table->string('x_robots_tag')->nullable();

            // Headings
            $table->jsonb('h1_tags')->nullable();
            $table->smallInteger('h1_count')->default(0);
            $table->smallInteger('h2_count')->default(0);
            $table->smallInteger('h3_count')->default(0);

            // Content
            $table->integer('word_count')->default(0);
            $table->float('readability_score')->nullable();

            // Links
            $table->integer('internal_links_count')->default(0);
            $table->integer('external_links_count')->default(0);
            $table->jsonb('internal_links')->nullable();
            $table->jsonb('external_links')->nullable();

            // Images
            $table->integer('images_count')->default(0);
            $table->integer('images_without_alt')->default(0);

            // Structured data
            $table->jsonb('structured_data_types')->nullable();
            $table->jsonb('hreflang')->nullable();

            // OG
            $table->string('og_title')->nullable();
            $table->string('og_description')->nullable();
            $table->string('og_image', 2048)->nullable();

            // Redirects
            $table->string('redirect_url', 2048)->nullable();
            $table->smallInteger('redirect_status_code')->nullable();

            // Issues found on this page
            $table->jsonb('issues')->nullable();

            $table->timestamp('crawled_at')->nullable();
            $table->timestamps();

            $table->index('site_crawl_id');
            $table->index(['site_crawl_id', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawled_pages');
    }
};
