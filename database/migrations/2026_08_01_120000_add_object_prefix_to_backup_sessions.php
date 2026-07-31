<?php

declare(strict_types=1);

use App\Backup\V2\Storage\ObjectLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freezes where a backup's objects live.
 *
 * The prefix was computed from `backup_id ?? id` at every point of use — in the
 * backup runner, the restore runner, the pre-restore safety backup, and (with a
 * third, inconsistent formula) the deep-verify command. That is a live hazard:
 * populating `backup_sessions.backup_id` to link a session to its V1 row, which
 * the product plan requires, silently moves the prefix and orphans every object
 * already written under the old one.
 *
 * Storing the prefix once, at the moment the first object is written, removes
 * the question entirely. Nothing recomputes it afterwards; ids and templates
 * become free to change.
 *
 * Backfilled from the current formula so the two sessions that already exist
 * keep addressing the objects they actually wrote.
 *
 * Raw DDL + non-transactional so it runs on the direct connection at deploy —
 * Schema:: builders break under PgBouncer transaction pooling.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE backup_sessions ADD COLUMN IF NOT EXISTS object_prefix varchar(500)');

        // Record where existing sessions already are, through the very code that
        // put them there. Rebuilding the formula in SQL would have hardcoded the
        // default template, and quietly written the wrong prefix for anyone who
        // had customised config('backup_v2.object_prefix').
        $rows = DB::table('backup_sessions as s')
            ->leftJoin('sites as si', 'si.id', '=', 's.site_id')
            ->whereNull('s.object_prefix')
            ->get(['s.id', 's.site_id', 's.backup_id', 'si.client_id']);

        foreach ($rows as $row) {
            $prefix = ObjectLayout::forBackup(
                clientId: $row->client_id ?? 0,
                siteId: $row->site_id,
                backupId: $row->backup_id ?? $row->id,
            )->prefix();

            DB::table('backup_sessions')->where('id', $row->id)->update(['object_prefix' => $prefix]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backup_sessions DROP COLUMN IF EXISTS object_prefix');
    }
};
