<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_monitor_id')->constrained()->cascadeOnDelete();

            // Test identity
            $table->string('device'); // mobile, desktop
            $table->string('url');

            // Lighthouse scores (0-100)
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('accessibility_score')->nullable();
            $table->unsignedTinyInteger('best_practices_score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();

            // Lab metrics
            $table->float('fcp')->nullable();  // First Contentful Paint (seconds)
            $table->float('lcp')->nullable();  // Largest Contentful Paint (seconds)
            $table->float('cls')->nullable();  // Cumulative Layout Shift
            $table->float('tbt')->nullable();  // Total Blocking Time (ms)
            $table->float('si')->nullable();   // Speed Index (seconds)
            $table->float('tti')->nullable();  // Time to Interactive (seconds)

            // Field (CrUX) metrics
            $table->float('field_fcp')->nullable();
            $table->float('field_lcp')->nullable();
            $table->float('field_cls')->nullable();
            $table->float('field_inp')->nullable();
            $table->float('field_ttfb')->nullable();

            // Page stats
            $table->unsignedInteger('total_requests')->nullable();
            $table->unsignedBigInteger('total_size_bytes')->nullable();
            $table->unsignedBigInteger('html_size')->nullable();
            $table->unsignedBigInteger('css_size')->nullable();
            $table->unsignedBigInteger('js_size')->nullable();
            $table->unsignedBigInteger('image_size')->nullable();
            $table->unsignedBigInteger('font_size')->nullable();

            // Detailed results
            $table->json('opportunities')->nullable();
            $table->json('diagnostics')->nullable();

            // Status
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->text('error_message')->nullable();
            $table->string('lighthouse_version')->nullable();

            // Timestamp
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'device', 'tested_at']);
            $table->index(['performance_monitor_id', 'tested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_tests');
    }
};
