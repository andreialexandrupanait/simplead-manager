<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->string('status')->default('scheduled'); // scheduled, active, completed, cancelled
            $table->boolean('pause_uptime')->default(true);
            $table->boolean('pause_ssl')->default(false);
            $table->boolean('pause_performance')->default(false);
            $table->boolean('pause_backups')->default(false);
            $table->boolean('pause_links')->default(false);
            $table->boolean('notify_on_start')->default(true);
            $table->boolean('notify_on_end')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['status', 'scheduled_start_at']);
            $table->index(['status', 'scheduled_end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
