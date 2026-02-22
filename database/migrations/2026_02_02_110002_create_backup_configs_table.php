<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('frequency')->default('daily'); // daily, weekly, monthly
            $table->string('time')->default('03:00');
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sun..6=Sat
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..28
            $table->string('timezone')->default('UTC');
            $table->string('type')->default('full'); // full, database
            $table->json('exclude_paths')->nullable();
            $table->json('exclude_tables')->nullable();
            $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('retention_type')->default('count'); // count, days
            $table->unsignedInteger('retention_value')->default(10);
            $table->boolean('backup_before_updates')->default(false);
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamp('next_backup_at')->nullable();
            $table->string('last_backup_status')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index(['is_enabled', 'next_backup_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_configs');
    }
};
