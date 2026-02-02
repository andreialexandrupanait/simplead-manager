<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // local, dropbox, s3
            $table->json('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_passed')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_destinations');
    }
};
