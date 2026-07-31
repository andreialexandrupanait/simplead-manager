<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §7.5: key URLs are "derivate automat, suprascriabile", with
 * "recalculare trimestrială, cu blocare pe cele suprascrise manual".
 *
 * There was no lock and no way to override at all: deriveAndStore() overwrote
 * sites.key_urls unconditionally, on every WP sync and every Search Console
 * fetch. Even if a UI had existed, the next sync would have erased the choice.
 *
 * key_urls_locked_at doubles as the flag and the record of when it was pinned.
 * key_urls_derived_at is what makes "quarterly" mean anything — the recompute
 * had no idea when it last ran, so it simply ran every time.
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct connection
 * at deploy (Schema:: builders break under PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS key_urls_locked_at timestamptz');
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS key_urls_derived_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS key_urls_locked_at');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS key_urls_derived_at');
    }
};
