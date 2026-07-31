<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remembers when a site was last reported as having no recent backup.
 *
 * The stale check is a daily sweep, and NotificationService dedups on a
 * five-minute window — right for a burst of events, useless here. Without a
 * persisted marker a site with no backup would be reported every morning until
 * someone fixed it, which is how an alert channel becomes background noise.
 *
 * Cleared the moment a backup succeeds again, so the next lapse alerts afresh
 * rather than staying silent behind a stale marker.
 *
 * Raw DDL + non-transactional so it runs on the direct connection at deploy —
 * Schema:: builders break under PgBouncer transaction pooling.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE backup_configs ADD COLUMN IF NOT EXISTS stale_alert_sent_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backup_configs DROP COLUMN IF EXISTS stale_alert_sent_at');
    }
};
