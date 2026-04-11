<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_file_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('theme_slug');
            $table->string('theme_version')->nullable();
            $table->integer('total_files')->default(0);
            $table->integer('modified_count')->default(0);
            $table->integer('unknown_count')->default(0);
            $table->jsonb('modified_files')->nullable();
            $table->jsonb('unknown_files')->nullable();
            $table->jsonb('baseline_hashes')->nullable();
            $table->string('status'); // clean, modified, error, baseline
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'theme_slug']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_file_checks');
    }
};
