<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Configuration
            $table->boolean('is_active')->default(true);
            $table->string('frequency')->default('daily'); // manual, daily, weekly
            $table->string('test_time')->default('04:00');
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday, 6=Saturday

            // Alert configuration
            $table->boolean('alert_on_score_drop')->default(true);
            $table->unsignedTinyInteger('score_drop_threshold')->default(10);
            $table->boolean('alert_on_poor_vitals')->default(false);

            // Cached latest scores
            $table->unsignedTinyInteger('latest_mobile_score')->nullable();
            $table->unsignedTinyInteger('latest_desktop_score')->nullable();
            $table->unsignedTinyInteger('previous_mobile_score')->nullable();
            $table->unsignedTinyInteger('previous_desktop_score')->nullable();

            // Scheduling
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('next_test_at')->nullable();

            $table->timestamps();

            $table->index('site_id');
            $table->index(['is_active', 'next_test_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_monitors');
    }
};
