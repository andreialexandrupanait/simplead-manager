<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('uptime_monitors')->cascadeOnDelete();
            $table->string('status')->default('ongoing'); // ongoing, resolved
            $table->string('cause')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('notified_via')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'status']);
            $table->index(['monitor_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_incidents');
    }
};
