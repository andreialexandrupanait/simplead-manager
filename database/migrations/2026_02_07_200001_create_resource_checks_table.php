<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_usage', 5, 2)->nullable();
            $table->bigInteger('memory_used')->nullable();
            $table->bigInteger('memory_total')->nullable();
            $table->decimal('memory_percentage', 5, 2)->nullable();
            $table->bigInteger('disk_used')->nullable();
            $table->bigInteger('disk_total')->nullable();
            $table->decimal('disk_percentage', 5, 2)->nullable();
            $table->decimal('load_average_1', 5, 2)->nullable();
            $table->decimal('load_average_5', 5, 2)->nullable();
            $table->decimal('load_average_15', 5, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_checks');
    }
};
