<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('wp_user_id')->nullable();
            $table->string('wp_username')->nullable();
            $table->string('user_role')->nullable();
            $table->string('action_type');
            $table->string('object_type')->nullable();
            $table->string('object_id')->nullable();
            $table->string('object_title')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'action_type', 'wp_username', 'action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_audit_logs');
    }
};
