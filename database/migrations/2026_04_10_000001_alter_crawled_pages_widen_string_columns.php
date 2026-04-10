<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->text('meta_robots')->nullable()->change();
            $table->text('x_robots_tag')->nullable()->change();
            $table->text('og_title')->nullable()->change();
            $table->text('og_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->string('meta_robots')->nullable()->change();
            $table->string('x_robots_tag')->nullable()->change();
            $table->string('og_title')->nullable()->change();
            $table->string('og_description')->nullable()->change();
        });
    }
};
