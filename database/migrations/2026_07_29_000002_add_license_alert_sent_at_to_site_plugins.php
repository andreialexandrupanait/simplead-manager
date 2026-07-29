<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 5.4 — premium license-expiry alerting. `license_alert_sent_at` records
 * when the last expiry alert was emitted for a licensed plugin, so the daily
 * CheckLicenseExpiry job can dedup instead of re-alerting the same expiry every
 * morning. Cleared (NULL) once a license is renewed so a future expiry alerts afresh.
 *
 * Expand-only + idempotent (IF NOT EXISTS), non-transactional to match the other
 * DDL migrations that run on the direct (non-PgBouncer) connection at deploy.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE site_plugins ADD COLUMN IF NOT EXISTS license_alert_sent_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_plugins DROP COLUMN IF EXISTS license_alert_sent_at');
    }
};
