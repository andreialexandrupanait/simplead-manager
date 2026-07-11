<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SecurityCommand pull-queue never had a consumer — no WordPress-side
 * poller was ever shipped, so rows only accumulated as perpetually-pending
 * queue debris while the real enforcement ran through PushSecuritySettings.
 * The whole agent-pull path is removed; down() restores an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('security_commands');
    }

    public function down(): void
    {
        Schema::create('security_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('action', 100);
            $table->jsonb('payload')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('pending');
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('max_attempts')->default(3);
            $table->timestamps();
        });
    }
};
