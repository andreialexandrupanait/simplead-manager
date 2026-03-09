<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->onDelete('cascade');

            $table->string('date_range');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('data_type');

            $table->json('data');

            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');

            $table->index(['site_id', 'date_range', 'data_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_cache');
    }
};
