<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site redirect rules, pushed to the connector which performs them on the
 * front end. Primary use: turning a broken link into a 301 to the right page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('source_path');
            $table->string('target_url');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'source_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_redirects');
    }
};
