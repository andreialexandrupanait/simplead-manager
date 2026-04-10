<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->integer('critical_count')->default(0);
            $table->integer('high_count')->default(0);
            $table->integer('medium_count')->default(0);
            $table->integer('low_count')->default(0);
            $table->integer('info_count')->default(0);
            $table->integer('scan_duration')->nullable();
            $table->integer('pages_crawled')->default(0);
            $table->string('seo_plugin')->nullable();
            $table->string('seo_plugin_version')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audits');
    }
};
