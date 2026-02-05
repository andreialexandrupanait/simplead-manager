<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('total_size')->default(0);
            $table->unsignedInteger('total_tables')->default(0);
            $table->json('tables_data')->nullable();
            $table->json('largest_tables')->nullable();
            $table->json('tables_with_overhead')->nullable();
            $table->unsignedInteger('myisam_count')->default(0);
            $table->unsignedBigInteger('autoload_size')->default(0);
            $table->string('status')->default('healthy');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_health_checks');
    }
};
