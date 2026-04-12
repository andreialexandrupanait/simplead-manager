<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048); $table->string('url_hash', 64);
            $table->smallInteger('status_code')->nullable(); $table->smallInteger('depth')->default(0); $table->string('content_type', 100)->nullable();
            $table->string('title', 1000)->nullable(); $table->smallInteger('title_length')->nullable();
            $table->text('meta_description')->nullable(); $table->smallInteger('meta_description_length')->nullable();
            $table->jsonb('h1_tags')->nullable(); $table->jsonb('heading_structure')->nullable();
            $table->integer('word_count')->nullable(); $table->integer('image_count')->nullable(); $table->integer('images_without_alt')->nullable();
            $table->string('canonical_url', 2048)->nullable(); $table->boolean('is_self_canonical')->nullable();
            $table->string('meta_robots', 255)->nullable(); $table->boolean('is_indexable')->nullable();
            $table->boolean('in_sitemap')->default(false); $table->boolean('blocked_by_robots')->default(false);
            $table->integer('internal_link_count')->default(0); $table->integer('external_link_count')->default(0); $table->integer('inbound_internal_links')->default(0);
            $table->string('redirect_target', 2048)->nullable(); $table->smallInteger('redirect_chain_length')->default(0);
            $table->integer('page_size_bytes')->nullable(); $table->float('ttfb_seconds')->nullable();
            $table->jsonb('structured_data_types')->nullable(); $table->jsonb('og_tags')->nullable(); $table->jsonb('twitter_tags')->nullable();
            $table->boolean('has_viewport_meta')->nullable(); $table->jsonb('meta')->nullable(); $table->timestamps();
            $table->index('url_hash'); $table->index(['seo_audit_id', 'status_code']); $table->index(['seo_audit_id', 'is_indexable']); $table->index(['site_id', 'url_hash']);
        });
    }
    public function down(): void { Schema::dropIfExists('seo_pages'); }
};
