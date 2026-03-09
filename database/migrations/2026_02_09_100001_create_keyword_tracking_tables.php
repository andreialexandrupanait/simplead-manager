<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->timestamps();
            $table->unique(['site_id', 'keyword']);
        });

        Schema::create('keyword_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_keyword_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->float('position')->nullable();
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->float('ctr')->default(0);
            $table->timestamps();
            $table->unique(['tracked_keyword_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_positions');
        Schema::dropIfExists('tracked_keywords');
    }
};
