<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Configuration
            $table->string('type')->default('http'); // http, https, keyword, ping
            $table->string('url');
            $table->integer('interval')->default(300); // seconds
            $table->integer('timeout')->default(30); // seconds
            $table->string('http_method')->default('GET');
            $table->json('http_headers')->nullable();
            $table->text('http_body')->nullable();
            $table->json('accepted_status_codes')->nullable(); // e.g. [200, 201, 301]
            $table->boolean('follow_redirects')->default(true);

            // Authentication
            $table->string('auth_type')->nullable(); // basic, bearer, none
            $table->string('auth_username')->nullable();
            $table->text('auth_password')->nullable(); // encrypted
            $table->text('auth_token')->nullable(); // encrypted

            // Keyword checking
            $table->string('keyword')->nullable();
            $table->string('keyword_type')->nullable(); // exists, not_exists
            $table->boolean('keyword_case_sensitive')->default(false);

            // SSL monitoring
            $table->boolean('check_ssl')->default(true);
            $table->integer('ssl_expiry_threshold')->default(14); // days

            // Alert configuration
            $table->integer('alert_after_failures')->default(3);
            $table->json('alert_contacts')->nullable(); // channel IDs
            $table->integer('consecutive_failures')->default(0);

            // State
            $table->string('status')->default('active'); // active, paused
            $table->string('current_state')->default('unknown'); // up, down, degraded, unknown
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_state_change_at')->nullable();

            // Cached statistics
            $table->decimal('uptime_24h', 6, 3)->nullable();
            $table->decimal('uptime_7d', 6, 3)->nullable();
            $table->decimal('uptime_30d', 6, 3)->nullable();
            $table->decimal('uptime_365d', 6, 3)->nullable();
            $table->integer('avg_response_time')->nullable(); // ms
            $table->integer('last_response_time')->nullable(); // ms
            $table->string('last_failure_reason')->nullable();

            $table->timestamps();

            $table->index('site_id');
            $table->index(['status', 'current_state']);
            $table->index('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_monitors');
    }
};
