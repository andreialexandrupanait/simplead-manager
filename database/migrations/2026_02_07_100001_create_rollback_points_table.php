<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['plugin', 'theme', 'core']);
            $table->string('slug');
            $table->string('from_version');
            $table->string('to_version');
            $table->string('backup_reference')->nullable();
            $table->enum('status', ['available', 'used', 'expired'])->default('available');
            $table->timestamps();
            $table->timestamp('expires_at')->nullable();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollback_points');
    }
};
