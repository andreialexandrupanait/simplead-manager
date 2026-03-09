<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address');
            $table->string('request_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'ip_address', 'blocked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_requests');
    }
};
