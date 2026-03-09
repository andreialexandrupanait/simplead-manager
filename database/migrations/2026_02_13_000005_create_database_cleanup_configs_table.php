<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_cleanup_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('frequency')->default('monthly'); // daily, weekly, monthly
            $table->json('auto_clean_types')->nullable(); // e.g. ['revisions', 'spam', 'trash', 'transients']
            $table->timestamp('next_cleanup_at')->nullable();
            $table->timestamp('last_cleanup_at')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'next_cleanup_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_cleanup_configs');
    }
};
