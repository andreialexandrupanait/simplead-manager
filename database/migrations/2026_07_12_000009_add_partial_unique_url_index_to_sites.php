<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3-12: allow re-adding a URL whose only conflict is a SOFT-DELETED site.
 *
 * Historically `sites.url` had no DB-level unique constraint at all — uniqueness
 * was enforced only by the Livewire validation rule, which counted soft-deleted
 * rows (so a removed site's URL could never be re-added, and two concurrent adds
 * of the same URL could both slip through).
 *
 * This adds a PARTIAL unique index that only applies to live (non-deleted) rows,
 * so soft-deleted sites never collide while live duplicates are still rejected —
 * mirroring the soft-delete-aware validation rule in SiteWizardFormData.
 *
 * Expand-only + idempotent (IF NOT EXISTS). Non-transactional so it runs cleanly
 * on the direct (non-PgBouncer) connection used by deploy.sh.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS sites_url_unique_active '.
            'ON sites (url) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_url_unique_active');
    }
};
