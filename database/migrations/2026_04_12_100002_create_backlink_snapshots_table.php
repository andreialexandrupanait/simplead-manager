<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('total_backlinks')->default(0);
            $table->integer('referring_domains')->default(0);
            $table->integer('new_backlinks')->default(0);
            $table->integer('lost_backlinks')->default(0);
            $table->integer('dofollow_count')->default(0);
            $table->integer('nofollow_count')->default(0);
            $table->jsonb('anchor_text_distribution')->default('[]');
            $table->jsonb('top_pages')->default('[]');
            $table->timestamps();

            $table->unique(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlink_snapshots');
    }
};
