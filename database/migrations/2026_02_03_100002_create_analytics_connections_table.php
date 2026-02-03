<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->onDelete('cascade');
            $table->foreignId('google_connection_id')->constrained()->onDelete('cascade');

            $table->string('property_id');
            $table->string('property_name')->nullable();

            $table->string('data_stream_id')->nullable();
            $table->string('data_stream_url')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_connections');
    }
};
