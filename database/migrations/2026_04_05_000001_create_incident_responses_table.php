<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('incident_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_type', 50);
            $table->string('trigger_source', 100);
            $table->unsignedBigInteger('trigger_source_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('resolution_method', 30)->nullable();
            $table->string('playbook_name', 100)->nullable();
            $table->jsonb('diagnosis')->nullable();
            $table->jsonb('actions_taken')->nullable();
            $table->jsonb('ai_context')->nullable();
            $table->text('summary')->nullable();
            $table->integer('actions_count')->default(0);
            $table->integer('ai_calls_count')->default(0);
            $table->integer('total_tokens_used')->default(0);
            $table->boolean('backup_created')->default(false);
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('escalated_at')->nullable();

            $table->index(['site_id', 'status']);
            $table->index(['trigger_type', 'created_at']);
            $table->index(['site_id', 'trigger_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_responses');
    }
};
