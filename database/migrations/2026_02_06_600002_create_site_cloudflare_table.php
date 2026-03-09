<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_cloudflare', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('cloudflare_connection_id')->constrained()->cascadeOnDelete();
            $table->string('zone_id');
            $table->string('zone_name');
            $table->string('plan_type')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_paused')->default(false);
            $table->string('ssl_mode')->nullable();
            $table->string('cache_level')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_cloudflare');
    }
};
