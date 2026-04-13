<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('site_contents');
    }

    public function down(): void
    {
        Schema::create('site_contents', function ($table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->string('title');
            $table->string('type')->default('post');
            $table->string('status')->default('publish');
            $table->string('url')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->string('author_name')->nullable();
            $table->unsignedInteger('days_since_modified')->default(0);
            $table->boolean('is_stale')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wp_post_id']);
        });
    }
};
