<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_check_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ssl_certificate_id')->constrained('ssl_certificates')->cascadeOnDelete();

            $table->string('status');
            $table->integer('days_remaining')->nullable();
            $table->string('issuer')->nullable();
            $table->string('protocol')->nullable();
            $table->string('cipher')->nullable();
            $table->boolean('chain_valid')->default(false);
            $table->integer('handshake_time')->nullable(); // ms
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');

            $table->index('ssl_certificate_id');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_check_history');
    }
};
