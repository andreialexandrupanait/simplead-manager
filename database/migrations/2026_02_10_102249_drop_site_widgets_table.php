<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('site_widgets');
    }

    public function down(): void
    {
        // Recreate table for rollback
        Schema::create('site_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('widget_type', 50);
            $table->json('config')->nullable();
            $table->unsignedTinyInteger('grid_x')->default(0);
            $table->unsignedTinyInteger('grid_y')->default(0);
            $table->unsignedTinyInteger('grid_w')->default(4);
            $table->unsignedTinyInteger('grid_h')->default(2);
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'site_id', 'sort_order'], 'idx_user_site_sort');
            $table->unique(['user_id', 'site_id', 'widget_type'], 'unique_user_site_widget');
        });
    }
};
