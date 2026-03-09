<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_a_slug');
            $table->string('plugin_b_slug');
            $table->string('conflict_type');
            $table->text('description');
            $table->string('severity');
            $table->string('source_url')->nullable();
            $table->timestamps();

            $table->index(['plugin_a_slug', 'plugin_b_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_conflicts');
    }
};
