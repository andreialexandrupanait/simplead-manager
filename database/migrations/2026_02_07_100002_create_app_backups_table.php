<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_backups', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // full, database, config, storage
            $table->string('trigger')->default('manual'); // manual, scheduled
            $table->json('components')->nullable(); // ["database","env","storage","logs","codebase"]
            $table->string('status')->default('pending'); // pending, in_progress, completed, failed
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->text('error_message')->nullable();
            $table->json('log')->nullable();
            $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('storage_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->json('component_sizes')->nullable(); // {"database": 123456, "env": 1234, ...}
            $table->string('app_version')->nullable();
            $table->string('laravel_version')->nullable();
            $table->string('php_version')->nullable();
            $table->unsignedInteger('sites_count')->default(0);
            $table->unsignedInteger('users_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->string('lock_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_backups');
    }
};
