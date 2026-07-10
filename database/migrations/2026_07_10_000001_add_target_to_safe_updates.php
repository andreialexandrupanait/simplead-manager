<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the exact connector identifier a safe update must send.
 *
 * Plugins are updated by their plugin FILE (e.g. `akismet/akismet.php`), not
 * their slug; themes and core use the slug/none. Persisting `target` lets the
 * update be dispatched correctly from the queued job (RunSafeUpdate) without
 * re-deriving the file. Nullable so existing rows fall back to `slug`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_updates', function (Blueprint $table) {
            $table->string('target')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('safe_updates', function (Blueprint $table) {
            $table->dropColumn('target');
        });
    }
};
