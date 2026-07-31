<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Write out the backup encryption keys, so losing this installation does not
 * mean losing every backup it ever took.
 *
 * Encrypting on our side buys real protection — the storage provider cannot read
 * a client's database, and a leaked bucket key is worth nothing on its own — and
 * it buys exactly one new way to lose everything: the keys live in a column
 * sealed with APP_KEY, so APP_KEY becomes the root of the scheme. Restore a
 * database backup onto a host with a different APP_KEY and every object in the
 * bucket becomes permanently unreadable.
 *
 * That is not an argument against encrypting. It is an argument for this command
 * existing before the first encrypted backup does, and for its output living
 * somewhere that is not this server.
 *
 * The output contains raw keys in base64. It is written to stdout only, never to
 * a file in the project, and the operator is told plainly what they are holding.
 */
class ExportKeysCommand extends Command
{
    protected $signature = 'backup:export-keys {--json : Emit the bundle as JSON for scripted storage}';

    protected $description = 'Export the per-client backup encryption keys for offline safekeeping';

    public function handle(): int
    {
        $clients = Client::query()
            ->whereNotNull('backup_key')
            ->orderBy('id')
            ->get();

        if ($clients->isEmpty()) {
            $this->info('No backup encryption keys exist yet — no client has been backed up by this engine.');

            return self::SUCCESS;
        }

        /** @var list<array{client_id:int,client_name:string,key_id:string,key_base64:string}> $bundle */
        $bundle = $clients->map(fn (Client $c): array => [
            'client_id' => $c->id,
            'client_name' => $c->name,
            'key_id' => $c->backup_key_id,
            'key_base64' => base64_encode((string) $c->backup_key),
        ])->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'exported_at' => now()->toIso8601String(),
                'app_url' => config('app.url'),
                'keys' => $bundle,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->warn('These are the keys that decrypt every backup this installation has taken.');
        $this->warn('Anyone holding them can read those backups. Store them offline, not on this server.');
        $this->newLine();

        $this->table(
            ['Client', 'Name', 'Key ID', 'Key (base64)'],
            array_map(static fn (array $k): array => array_values($k), $bundle),
        );

        $this->newLine();
        $this->line(sprintf(
            'Without these, a rebuilt installation with a different APP_KEY cannot read %d client(s) worth of backups.',
            count($bundle),
        ));

        return self::SUCCESS;
    }
}
