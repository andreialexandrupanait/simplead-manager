<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Security Settings — per-site hardening configuration
        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('setting_key', 100);
            $table->jsonb('setting_value')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'category', 'setting_key']);
            $table->index(['site_id', 'category']);
        });

        // 2. Security Commands — command queue for WordPress agents
        Schema::create('security_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('action', 100);
            $table->jsonb('payload')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('pending');
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'category', 'action']);
        });

        // 3. Security Presets — reusable hardening configurations
        Schema::create('security_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('settings');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 4. Security Preset ↔ Site pivot
        Schema::create('security_preset_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('security_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('applied_version')->default(1);

            $table->unique(['security_preset_id', 'site_id']);
        });

        // 5. Security Activity Logs — ingested from WordPress agents
        Schema::create('security_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('username')->nullable();
            $table->string('object_type', 50)->nullable();
            $table->string('object_name')->nullable();
            $table->string('action', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'occurred_at']);
            $table->index('ip_address');
            $table->index(['site_id', 'event_type']);
        });

        // 6. Security IP Lists — whitelist/blocklist per-site or global
        Schema::create('security_ip_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->ipAddress('ip_address');
            $table->string('list_type', 20); // whitelist, blocklist
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'list_type', 'ip_address']);
        });

        // 7. Security Banned IPs — auto-blocked IPs from brute force detection
        Schema::create('security_banned_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->ipAddress('ip_address');
            $table->text('reason')->nullable();
            $table->unsignedInteger('blocked_attempts')->default(0);
            $table->timestamp('banned_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'ip_address']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_banned_ips');
        Schema::dropIfExists('security_ip_lists');
        Schema::dropIfExists('security_activity_logs');
        Schema::dropIfExists('security_preset_site');
        Schema::dropIfExists('security_presets');
        Schema::dropIfExists('security_commands');
        Schema::dropIfExists('security_settings');
    }
};
