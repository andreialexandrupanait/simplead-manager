<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form labels for sites (prod/staging, plan tier, client segment, …) so
 * the fleet can be grouped and filtered by something other than the single
 * client relationship. Tags are org-level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 32)->default('gray');
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('site_tag', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tag');
        Schema::dropIfExists('tags');
    }
};
