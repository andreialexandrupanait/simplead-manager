<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §5.4 asks for "verificare de livrare" on the contact-form test.
 *
 * `delivery_verified` existed from the start and was never written, because the
 * connector aborted the marked notification: there was no message left to look
 * for. Connector 2.21.0 redirects it to a test mailbox instead, so these two
 * columns record where it was sent and when it was found.
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct connection
 * at deploy (Schema:: builders break under PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE form_checks ADD COLUMN IF NOT EXISTS delivered_to varchar(191)');
        DB::statement('ALTER TABLE form_checks ADD COLUMN IF NOT EXISTS delivery_verified_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE form_checks DROP COLUMN IF EXISTS delivered_to');
        DB::statement('ALTER TABLE form_checks DROP COLUMN IF EXISTS delivery_verified_at');
    }
};
