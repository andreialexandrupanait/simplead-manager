<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_scan_id')->constrained()->cascadeOnDelete();

            // URL info
            $table->string('url', 2048);
            $table->string('url_hash', 64)->index();
            $table->string('type')->default('internal'); // internal, external
            $table->string('link_type')->default('anchor'); // anchor, image, script, stylesheet

            // Source info
            $table->string('source_url', 2048)->nullable();
            $table->string('source_title')->nullable();
            $table->string('anchor_text', 500)->nullable();
            $table->string('element')->nullable(); // a, img, script, link

            // Check results
            $table->string('status')->default('pending'); // ok, broken, redirect, timeout, ssl_error, dns_error, pending
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->unsignedTinyInteger('redirect_count')->default(0);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_permanent_redirect')->default(false);

            // Dismiss
            $table->boolean('is_dismissed')->default(false);
            $table->string('dismissed_reason')->nullable();

            // Timestamps
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['link_scan_id', 'status']);
            $table->index(['site_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
