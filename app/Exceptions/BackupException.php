<?php

namespace App\Exceptions;

use App\Models\Backup;
use App\Models\Site;

class BackupException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Site $site = null,
        public readonly ?Backup $backup = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return array_filter([
            'site_id' => $this->site?->id,
            'site_name' => $this->site?->name,
            'backup_id' => $this->backup?->id,
        ]);
    }
}
