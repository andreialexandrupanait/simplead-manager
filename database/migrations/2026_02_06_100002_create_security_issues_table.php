<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('security_scan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('type');
            $table->string('severity');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('recommendation')->nullable();
            $table->boolean('is_fixed')->default(false);
            $table->boolean('is_ignored')->default(false);
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'severity', 'is_fixed', 'is_ignored']);
            $table->unique(['site_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_issues');
    }
};
