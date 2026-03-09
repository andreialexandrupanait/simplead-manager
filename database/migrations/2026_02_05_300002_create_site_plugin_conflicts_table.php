<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_plugin_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('plugin_a_slug');
            $table->string('plugin_b_slug');
            $table->foreignId('plugin_conflict_id')->nullable()->constrained('plugin_conflicts')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->unique(['site_id', 'plugin_a_slug', 'plugin_b_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plugin_conflicts');
    }
};
