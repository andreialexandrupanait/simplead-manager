<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_contents', function (Blueprint $table) {
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
            $table->index(['site_id', 'is_stale']);
            $table->index(['site_id', 'days_since_modified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_contents');
    }
};
