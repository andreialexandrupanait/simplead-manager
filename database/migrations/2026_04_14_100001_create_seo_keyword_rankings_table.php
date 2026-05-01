<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('seo_keyword_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 500);
            $table->string('keyword_hash', 64)->index();
            $table->string('url', 2048)->nullable();
            $table->decimal('position', 6, 2)->nullable();
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->date('recorded_date');
            $table->boolean('is_tracked')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'keyword_hash', 'recorded_date']);
            $table->index(['site_id', 'is_tracked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keyword_rankings');
    }
};
