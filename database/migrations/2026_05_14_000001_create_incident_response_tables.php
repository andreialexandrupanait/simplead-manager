<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_responses')) {
            Schema::create('incident_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->string('trigger_type', 50);
                $table->string('trigger_source')->nullable();
                $table->unsignedBigInteger('trigger_source_id')->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('resolution_method')->nullable();
                $table->string('playbook_name')->nullable();
                $table->jsonb('diagnosis')->nullable();
                $table->jsonb('actions_taken')->nullable();
                $table->jsonb('ai_context')->nullable();
                $table->text('summary')->nullable();
                $table->integer('actions_count')->default(0);
                $table->integer('ai_calls_count')->default(0);
                $table->integer('total_tokens_used')->default(0);
                $table->boolean('backup_created')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('escalated_at')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'status']);
                $table->index(['site_id', 'trigger_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('incident_response_actions')) {
            Schema::create('incident_response_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_response_id')->constrained()->cascadeOnDelete();
                $table->string('action_type', 100);
                $table->string('tier', 30);
                $table->jsonb('parameters')->nullable();
                $table->jsonb('result')->nullable();
                $table->string('status', 30)->default('pending');
                $table->text('error_message')->nullable();
                $table->integer('duration_ms')->nullable();
                $table->integer('sequence')->default(0);
                $table->timestamps();

                $table->index('incident_response_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_response_actions');
        Schema::dropIfExists('incident_responses');
    }
};
