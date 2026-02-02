<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Certificate details
            $table->string('domain');
            $table->string('issuer')->nullable();
            $table->string('issuer_organisation')->nullable();
            $table->json('san_domains')->nullable();
            $table->string('signature_algorithm')->nullable();
            $table->integer('key_size')->nullable();
            $table->string('protocol')->nullable();
            $table->string('cipher')->nullable();

            // Validity
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('days_remaining')->nullable();
            $table->boolean('chain_valid')->default(false);

            // Status
            $table->string('status')->default('pending'); // pending, valid, expiring_soon, expired, error
            $table->text('error_message')->nullable();
            $table->integer('handshake_time')->nullable(); // ms

            // Alert configuration
            $table->boolean('alerts_enabled')->default(true);
            $table->integer('warn_days')->default(30);
            $table->timestamp('last_alert_sent_at')->nullable();

            // Scheduling
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();

            $table->timestamps();

            $table->index('site_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('days_remaining');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
