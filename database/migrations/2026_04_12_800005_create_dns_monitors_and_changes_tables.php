<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('interval_minutes')->default(360);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->jsonb('current_records')->nullable();
            $table->jsonb('previous_records')->nullable();
            $table->boolean('has_changes')->default(false);
            $table->timestamps();

            $table->unique('site_id');
            $table->index(['is_active', 'next_check_at']);
        });

        Schema::create('dns_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_monitor_id')->constrained()->cascadeOnDelete();
            $table->string('record_type'); // A, AAAA, MX, NS, CNAME, TXT
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['dns_monitor_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_changes');
        Schema::dropIfExists('dns_monitors');
    }
};
