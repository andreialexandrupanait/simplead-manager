<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Domain details
            $table->string('domain');
            $table->string('tld')->nullable();
            $table->string('registrar')->nullable();
            $table->string('registrar_url')->nullable();

            // WHOIS dates
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('days_remaining')->nullable();

            // DNS info
            $table->json('nameservers')->nullable();
            $table->string('dns_provider')->nullable();
            $table->json('domain_statuses')->nullable();

            // Status
            $table->string('status')->default('pending'); // pending, active, expiring_soon, expired, error
            $table->text('error_message')->nullable();

            // Alert configuration
            $table->boolean('alerts_enabled')->default(true);
            $table->integer('warn_days')->default(30);
            $table->timestamp('last_alert_sent_at')->nullable();

            // Scheduling
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('site_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('days_remaining');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_monitors');
    }
};
