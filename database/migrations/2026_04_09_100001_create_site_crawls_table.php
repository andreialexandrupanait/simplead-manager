<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_crawls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, running, completed, failed, cancelled
            $table->integer('pages_found')->default(0);
            $table->integer('pages_crawled')->default(0);
            $table->integer('pages_with_issues')->default(0);
            $table->integer('errors_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->jsonb('config')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_crawls');
    }
};
