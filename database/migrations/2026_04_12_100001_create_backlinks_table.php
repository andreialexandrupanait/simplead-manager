<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->text('source_url');
            $table->text('target_url');
            $table->string('source_domain');
            $table->text('anchor_text')->nullable();
            $table->boolean('is_nofollow')->default(false);
            $table->date('first_seen_at');
            $table->date('last_seen_at');
            $table->date('lost_at')->nullable();
            $table->string('source_type', 30)->default('gsc');
            $table->timestamps();

            $table->index(['site_id', 'source_domain']);
            $table->index(['site_id', 'lost_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlinks');
    }
};
