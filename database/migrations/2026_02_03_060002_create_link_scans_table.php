<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_monitor_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending, in_progress, completed, failed
            $table->string('trigger')->default('manual'); // manual, scheduled

            // Stats
            $table->unsignedInteger('total_links')->default(0);
            $table->unsignedInteger('broken_links')->default(0);
            $table->unsignedInteger('redirects')->default(0);
            $table->unsignedInteger('timeouts')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);

            // Progress
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_message')->nullable();
            $table->text('error_message')->nullable();

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['link_monitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_scans');
    }
};
