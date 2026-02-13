<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_health_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->string('circuit_state')->default('closed'); // closed, open, half_open
            $table->timestamp('circuit_opened_at')->nullable();
            $table->integer('circuit_breaks_last_24h')->default(0);
            $table->timestamp('circuit_breaks_reset_at')->nullable();
            $table->boolean('is_monitoring_disabled')->default(false);
            $table->timestamps();

            $table->index(['circuit_state', 'is_monitoring_disabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_health_state');
    }
};
