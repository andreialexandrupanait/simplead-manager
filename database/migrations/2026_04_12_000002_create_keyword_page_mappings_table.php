<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_page_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_keyword_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('source', 30)->default('gsc_auto');
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->float('avg_position')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tracked_keyword_id', 'url']);
            $table->index(['site_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_page_mappings');
    }
};
