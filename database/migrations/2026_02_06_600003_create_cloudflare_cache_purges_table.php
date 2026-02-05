<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_cache_purges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_cloudflare_id')->constrained('site_cloudflare')->cascadeOnDelete();
            $table->string('type');
            $table->json('targets')->nullable();
            $table->foreignId('purged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_cache_purges');
    }
};
