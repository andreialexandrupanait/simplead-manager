<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_content_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->text('meta_description')->nullable();
            $table->string('source')->default('ai');
            $table->jsonb('generation_params')->nullable();
            $table->timestamps();

            $table->index('seo_content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_revisions');
    }
};
