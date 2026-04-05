<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('incident_response_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_response_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 100);
            $table->string('tier', 20);
            $table->jsonb('parameters')->nullable();
            $table->jsonb('result')->nullable();
            $table->string('status', 20);
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('sequence')->default(0);
            $table->timestamps();

            $table->index('incident_response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_response_actions');
    }
};
