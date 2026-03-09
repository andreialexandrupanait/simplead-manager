<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_color')->default('#7C3AED');
            $table->string('custom_domain')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('show_uptime_percentage')->default(true);
            $table->boolean('show_response_time')->default(false);
            $table->boolean('show_incident_history')->default(true);
            $table->unsignedInteger('incident_history_days')->default(90);
            $table->boolean('auto_incidents')->default(true);
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
