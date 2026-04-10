<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->jsonb('scripts')->nullable();
            $table->jsonb('stylesheets')->nullable();
            $table->boolean('is_https')->default(false);
            $table->boolean('has_mixed_content')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropColumn(['scripts', 'stylesheets', 'is_https', 'has_mixed_content']);
        });
    }
};
