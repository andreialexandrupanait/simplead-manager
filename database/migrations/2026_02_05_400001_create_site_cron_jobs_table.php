<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('hook');
            $table->string('schedule')->nullable();
            $table->unsignedInteger('interval')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->timestamp('last_run')->nullable();
            $table->json('arguments')->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();

            $table->index('site_id');
            $table->unique(['site_id', 'hook', 'schedule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_cron_jobs');
    }
};
