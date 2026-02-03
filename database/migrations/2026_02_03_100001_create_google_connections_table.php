<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();

            $table->string('google_id')->unique();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();

            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');

            $table->json('scopes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
