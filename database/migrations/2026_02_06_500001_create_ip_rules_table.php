<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address');
            $table->string('type');
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hits_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->boolean('is_synced')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'type', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_rules');
    }
};
