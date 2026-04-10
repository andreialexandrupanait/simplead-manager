<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('interval_minutes')->default(10080); // 7 days
            $table->timestamp('next_audit_at')->nullable();
            $table->timestamp('last_audit_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_audit_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_monitors');
    }
};
