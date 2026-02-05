<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_file_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('wp_version')->nullable();
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('modified_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->json('modified_files')->nullable();
            $table->json('missing_files')->nullable();
            $table->json('unknown_files')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_file_checks');
    }
};
