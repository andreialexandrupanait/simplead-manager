<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-02: real TOTP two-factor auth for Manager users. App-level 2FA was removed
 * in PR #34 (columns dropped); this reintroduces it properly — TOTP + recovery
 * codes, mandatory for admins with a short grace period.
 *
 * - two_factor_secret / two_factor_recovery_codes: encrypted at rest (cast on
 *   the model); never queried, so encryption is safe here (unlike the
 *   api_key_hash case). Nullable — set only once a user enrolls.
 * - two_factor_confirmed_at: set when the user verifies their first code, so a
 *   half-finished enrollment never gates login.
 * - two_factor_grace_started_at: stamped on an admin's first authenticated
 *   request without 2FA, so the grace window is measured from first exposure
 *   (existing admins aren't locked out the instant this deploys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('two_factor_grace_started_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_grace_started_at',
            ]);
        });
    }
};
