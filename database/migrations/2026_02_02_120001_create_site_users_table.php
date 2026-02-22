<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_user_id');
            $table->string('username');
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->string('role')->nullable();
            $table->string('avatar_url')->nullable();
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wp_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_users');
    }
};
