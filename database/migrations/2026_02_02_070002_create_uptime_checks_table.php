<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptime_monitors')->cascadeOnDelete();
            $table->boolean('is_up');
            $table->integer('response_time')->nullable(); // ms
            $table->smallInteger('status_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->boolean('keyword_found')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
            $table->index(['monitor_id', 'is_up']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
    }
};
