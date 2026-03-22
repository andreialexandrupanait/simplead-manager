<?php

namespace App\DTOs;

readonly class DashboardSummary
{
    public function __construct(
        public int $backupsToday,
        public int $failedBackups,
        public int $totalStorage,
        public int $pendingUpdates,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            backupsToday: $data['backups_today'],
            failedBackups: $data['failed_backups'],
            totalStorage: $data['total_storage'],
            pendingUpdates: $data['pending_updates'],
        );
    }

    public function toArray(): array
    {
        return [
            'backups_today' => $this->backupsToday,
            'failed_backups' => $this->failedBackups,
            'total_storage' => $this->totalStorage,
            'pending_updates' => $this->pendingUpdates,
        ];
    }
}
