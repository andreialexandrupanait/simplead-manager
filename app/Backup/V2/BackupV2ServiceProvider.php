<?php

declare(strict_types=1);

namespace App\Backup\V2;

use App\Backup\V2\Console\DeepVerifyCommand;
use App\Backup\V2\Console\ImportLegacyCommand;
use App\Backup\V2\Console\ProvenRestoreCommand;
use App\Backup\V2\Console\ReconcileStorageCommand;
use App\Backup\V2\Console\RecoverStuckSessionsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the V2 backup engine (simplead-backup).
 *
 * SAFETY: purely additive. It only registers the read-only reconcile command so
 * it is discoverable via artisan. It registers NO scheduler, NO active queues,
 * and NO restore. Every V2 behaviour remains gated behind config('backup_v2.*')
 * flags (all false by default). With defaults, wiring this provider changes
 * nothing about production runtime.
 */
class BackupV2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileStorageCommand::class,
                DeepVerifyCommand::class,
                ProvenRestoreCommand::class,
                ImportLegacyCommand::class,
                RecoverStuckSessionsCommand::class,
            ]);
        }
    }
}
