<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Backup\BackupBrowserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrecacheBackupFileList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public int $backupId,
        public ?string $filesPath,
        public bool $hasDatabase,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        BackupBrowserService::precache($this->backupId, $this->filesPath, $this->hasDatabase);
    }
}
