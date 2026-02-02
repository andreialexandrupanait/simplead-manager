<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('author')->nullable();
            $table->string('author_uri')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_child_theme')->default(false);
            $table->string('parent_theme')->nullable();
            $table->boolean('has_update')->default(false);
            $table->string('update_version')->nullable();
            $table->string('screenshot_url')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'is_active']);
            $table->index(['site_id', 'has_update']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_themes');
    }
};
