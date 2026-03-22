<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('seo_checks');

        if (Schema::hasColumn('performance_tests', 'seo_score')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->dropColumn('seo_score');
            });
        }
    }

    public function down(): void
    {
        Schema::create('seo_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->integer('score')->nullable();
            $table->json('checks')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasColumn('performance_tests', 'seo_score')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->integer('seo_score')->nullable()->after('best_practices_score');
            });
        }
    }
};
