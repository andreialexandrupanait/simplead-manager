<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('competitor_url');
            $table->string('competitor_name')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'competitor_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_sites');
    }
};
