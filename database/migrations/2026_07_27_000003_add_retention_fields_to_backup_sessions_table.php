<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 chain-safe retention fields for the V2 backup engine.
 *
 * Additive & fully reversible on V2's OWN `backup_sessions` table (never touches the V1 `backups`
 * table). All columns are nullable / default-off, so existing P2 rows and the V1 engine are
 * unaffected, and with config('backup_v2.*') flags off nothing ever writes here.
 *
 *   protected    — a restore point retention must never reclaim (pre-update lock, manual pin).
 *   verified_at  — when this session's stored backup was last integrity-verified (keep-last-verified).
 *   expires_at   — retention horizon; a chain is deletable only when EVERY member has expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_sessions', function (Blueprint $table) {
            $table->boolean('protected')->default(false)->after('chain_position');
            $table->timestamp('verified_at')->nullable()->after('protected');
            $table->timestamp('expires_at')->nullable()->after('verified_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_sessions', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['protected', 'verified_at', 'expires_at']);
        });
    }
};
