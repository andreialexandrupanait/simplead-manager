<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained('reports')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('priority', 10)->default('medium');
            $table->string('category', 20)->default('technical');
            $table->string('title', 255);
            $table->text('description');
            $table->boolean('is_auto_generated')->default(false);
            $table->boolean('is_included')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('site_id');
            $table->index(['report_id', 'is_included']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_recommendations');
    }
};
