<?php

namespace App\Console\Commands;

use App\Services\AppBackup\AppBackupService;
use Illuminate\Console\Command;

class AppBackupCommand extends Command
{
    protected $signature = 'app:backup
        {--type=full : Backup type (full, database, config, storage)}
        {--destination= : Storage destination ID}
        {--note= : Optional note for this backup}';

    protected $description = 'Create a backup of the application';

    public function handle(AppBackupService $service): int
    {
        $type = $this->option('type');
        $validTypes = ['full', 'database', 'config', 'storage'];

        if (! in_array($type, $validTypes)) {
            $this->error("Invalid backup type: {$type}. Valid types: ".implode(', ', $validTypes));

            return self::FAILURE;
        }

        $destinationId = $this->option('destination') ? (int) $this->option('destination') : null;

        $this->info("Starting {$type} application backup...");

        try {
            $backup = $service->createBackup(
                type: $type,
                trigger: 'cli',
                storageDestinationId: $destinationId,
                notes: $this->option('note'),
            );

            $this->newLine();
            $this->info('Backup completed successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['File', $backup->file_name],
                    ['Size', $backup->file_size_formatted],
                    ['Duration', $backup->duration_formatted ?? 'N/A'],
                    ['Checksum', $backup->checksum],
                    ['Components', implode(', ', $backup->components ?? [])],
                ]
            );

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
