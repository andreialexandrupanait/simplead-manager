<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('file');
            $table->string('slug');
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('author')->nullable();
            $table->string('author_uri')->nullable();
            $table->string('plugin_uri')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('has_update')->default(false);
            $table->string('update_version')->nullable();
            $table->string('requires_wp')->nullable();
            $table->string('requires_php')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'file']);
            $table->index(['site_id', 'is_active']);
            $table->index(['site_id', 'has_update']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plugins');
    }
};
