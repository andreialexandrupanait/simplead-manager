<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('status')->default('draft');
            $table->string('target_keyword')->nullable();
            $table->jsonb('secondary_keywords')->nullable();
            $table->text('brief')->nullable();
            $table->text('content')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('tone')->nullable();
            $table->string('persona')->nullable();
            $table->string('target_audience')->nullable();
            $table->integer('target_word_count')->nullable();
            $table->jsonb('sections')->nullable();
            $table->jsonb('seo_score_data')->nullable();
            $table->smallInteger('seo_score')->nullable();
            $table->integer('word_count')->nullable();
            $table->float('keyword_density')->nullable();
            $table->integer('wp_post_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['status', 'scheduled_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_contents');
    }
};
