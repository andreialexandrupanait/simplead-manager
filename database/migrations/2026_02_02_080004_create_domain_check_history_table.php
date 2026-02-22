<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_check_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_monitor_id')->constrained('domain_monitors')->cascadeOnDelete();

            $table->string('status');
            $table->integer('days_remaining')->nullable();
            $table->string('registrar')->nullable();
            $table->json('nameservers')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');

            $table->index('domain_monitor_id');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_check_history');
    }
};
