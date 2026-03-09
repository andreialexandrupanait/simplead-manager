<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            // Schedule config
            $table->string('frequency')->default('weekly'); // daily, weekly, monthly, manual
            $table->string('scan_time')->default('02:00');
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sun..6=Sat
            $table->unsignedInteger('max_pages')->default(200);
            $table->unsignedTinyInteger('max_depth')->default(5);
            $table->boolean('check_external')->default(true);
            $table->boolean('check_images')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->json('exclude_paths')->nullable();
            $table->json('exclude_domains')->nullable();

            // Alert config
            $table->boolean('alert_on_broken')->default(true);
            $table->unsignedInteger('alert_threshold')->default(1);

            // Cached stats
            $table->unsignedInteger('total_links')->default(0);
            $table->unsignedInteger('broken_links')->default(0);
            $table->unsignedInteger('redirects')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);

            // Timestamps
            $table->timestamp('last_scan_at')->nullable();
            $table->timestamp('next_scan_at')->nullable();
            $table->string('last_scan_status')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index(['is_active', 'next_scan_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_monitors');
    }
};
