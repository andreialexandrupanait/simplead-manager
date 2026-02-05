<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['plugin', 'theme', 'core']);
            $table->string('slug');
            $table->string('name');
            $table->string('from_version');
            $table->string('to_version');
            $table->enum('status', ['pending', 'backing_up', 'updating', 'health_checking', 'rolling_back', 'completed', 'failed'])->default('pending');
            $table->json('health_check_results')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('auto_rollback')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_updates');
    }
};
