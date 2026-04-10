<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_audit_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('severity');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->text('recommendation')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'severity']);
            $table->index('seo_audit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_issues');
    }
};
