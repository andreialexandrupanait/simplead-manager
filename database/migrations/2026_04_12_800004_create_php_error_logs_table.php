<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('php_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('level'); // fatal, warning, notice, deprecated
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('message_hash', 32);
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_resolved')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'message_hash']);
            $table->index(['site_id', 'level']);
            $table->index(['site_id', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('php_error_logs');
    }
};
