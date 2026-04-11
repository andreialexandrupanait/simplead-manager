<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_keyword_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_site_id')->constrained('competitor_sites')->cascadeOnDelete();
            $table->string('keyword');
            $table->float('position')->nullable();
            $table->text('url')->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index(['competitor_site_id', 'keyword', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_keyword_positions');
    }
};
