<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('seo_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_page_id')->constrained()->cascadeOnDelete();
            $table->string('image_url', 2048);
            $table->string('image_url_hash', 64);
            $table->string('alt_text', 1000)->nullable();
            $table->smallInteger('status_code')->nullable();
            $table->boolean('is_broken')->default(false);
            $table->boolean('has_alt')->default(true);
            $table->boolean('has_lazy_loading')->default(false);
            $table->integer('file_size_bytes')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->timestamps();

            $table->index(['seo_audit_id', 'is_broken']);
            $table->index(['seo_audit_id', 'image_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_images');
    }
};
