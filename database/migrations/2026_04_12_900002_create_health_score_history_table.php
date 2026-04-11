<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('score');
            $table->smallInteger('uptime_score');
            $table->smallInteger('security_score');
            $table->smallInteger('updates_score');
            $table->smallInteger('performance_score');
            $table->date('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['site_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_score_history');
    }
};
