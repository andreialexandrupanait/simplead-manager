<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P1-10 / E-28 backfill: sites without a `site_health_state` row are silently
 * skipped by the monitoring and data-sync dispatchers (they gate on that row),
 * so a connected site could be permanently un-uptime-checked and never synced.
 *
 * Site::created now guarantees the row for new sites; this backfills existing
 * ones. It is strictly additive (INSERT-only for sites that lack a row) — it
 * never touches or resets an existing health state, so live circuit-breaker
 * bookkeeping is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('sites')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('site_health_state')
                    ->whereColumn('site_health_state.site_id', 'sites.id');
            })
            ->orderBy('id')
            ->select('id')
            ->chunk(500, function ($sites) use ($now) {
                $rows = [];

                foreach ($sites as $site) {
                    $rows[] = [
                        'site_id' => $site->id,
                        'consecutive_failures' => 0,
                        'circuit_state' => 'closed',
                        'circuit_breaks_last_24h' => 0,
                        'is_monitoring_disabled' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    // Ignore any row that raced in via Site::created between the
                    // gap query and this insert (unique site_id constraint).
                    DB::table('site_health_state')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: the backfilled rows are indistinguishable
        // from legitimately-created health states and must not be deleted.
    }
};
