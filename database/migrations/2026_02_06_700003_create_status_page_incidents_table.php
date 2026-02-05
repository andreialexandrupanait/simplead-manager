<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('investigating');
            $table->string('severity')->default('minor');
            $table->boolean('is_scheduled')->default(false);
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('auto_created')->default(false);
            $table->timestamps();

            $table->index(['status_page_id', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_incidents');
    }
};
