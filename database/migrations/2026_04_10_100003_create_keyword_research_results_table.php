<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_research_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('seed_keyword');
            $table->string('language', 10)->default('ro');
            $table->string('country', 10)->default('ro');
            $table->jsonb('suggestions')->nullable();
            $table->jsonb('gsc_data')->nullable();
            $table->jsonb('clusters')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_research_results');
    }
};
