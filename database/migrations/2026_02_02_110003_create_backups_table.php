<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // full, database
            $table->string('trigger')->default('manual'); // manual, scheduled, pre_update
            $table->string('status')->default('pending'); // pending, in_progress, completed, failed
            $table->text('error_message')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->boolean('includes_files')->default(false);
            $table->boolean('includes_database')->default(false);
            $table->string('wp_version')->nullable();
            $table->string('php_version')->nullable();
            $table->unsignedInteger('plugins_count')->nullable();
            $table->unsignedInteger('themes_count')->nullable();
            $table->decimal('db_size_mb', 10, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->string('lock_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_restored_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'created_at']);
            $table->index('storage_destination_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
