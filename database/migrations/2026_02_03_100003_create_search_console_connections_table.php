<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->onDelete('cascade');
            $table->foreignId('google_connection_id')->constrained()->onDelete('cascade');

            $table->string('property_url');
            $table->string('property_type')->default('url');
            $table->string('permission_level')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_connections');
    }
};
