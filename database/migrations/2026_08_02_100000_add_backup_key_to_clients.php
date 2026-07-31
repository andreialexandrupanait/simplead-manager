<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One backup encryption key per client.
 *
 * A backup holds the client's entire database and every file they have
 * uploaded. Written in the clear, anyone who can read the bucket has all of it:
 * the storage provider, anyone with a leaked access key, anyone who finds a
 * misconfigured policy. Server-side encryption would move the key to the
 * provider, which answers a different question than the one worth answering.
 *
 * Per client rather than per site (a restore would have to find the right key
 * among hundreds) and rather than one for the platform (a single compromise
 * would reach every client's data). Per client is how the data is actually
 * owned.
 *
 * `backup_key` holds 32 random bytes under Laravel's `encrypted` cast, so at
 * rest it is sealed with APP_KEY and never appears in a query log or a dump of
 * this database. `backup_key_id` is the public label written into every object's
 * header, so a restore can tell which key it needs — including after a rotation,
 * and including when the answer is "one this installation no longer holds".
 *
 * Both nullable: a key is generated the first time a client is actually backed
 * up. Creating one for a client who has never had a backup would put a secret in
 * the database that protects nothing.
 *
 * Raw DDL + non-transactional so it runs on the direct connection at deploy —
 * Schema:: builders break under PgBouncer transaction pooling. ALTER on a live
 * table, so recycle PgBouncer afterwards.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE clients ADD COLUMN IF NOT EXISTS backup_key text');
        DB::statement('ALTER TABLE clients ADD COLUMN IF NOT EXISTS backup_key_id varchar(64)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS clients_backup_key_id_unique ON clients (backup_key_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clients_backup_key_id_unique');
        DB::statement('ALTER TABLE clients DROP COLUMN IF EXISTS backup_key_id');
        DB::statement('ALTER TABLE clients DROP COLUMN IF EXISTS backup_key');
    }
};
