<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

use App\Models\StorageDestination;
use InvalidArgumentException;

class StorageFactory
{
    public static function make(StorageDestination $destination): StorageDriver
    {
        return match ($destination->type) {
            'local' => new LocalDriver($destination->config ?? []),
            'dropbox' => new DropboxDriver($destination),
            's3', 'b2', 'hetzner_objectstorage' => new S3Driver($destination->config ?? []),
            default => throw new InvalidArgumentException("Unsupported storage type: {$destination->type}"),
        };
    }

    /**
     * Region → endpoint mapping for S3-compatible providers. Returned config also
     * includes use_path_style hint where the provider requires it.
     *
     * @return array{endpoint: string, use_path_style: bool}|null
     */
    public static function endpointFor(string $type, string $region): ?array
    {
        $region = strtolower(trim($region));

        return match ($type) {
            'b2' => [
                'endpoint' => "https://s3.{$region}.backblazeb2.com",
                'use_path_style' => true,
            ],
            'hetzner_objectstorage' => [
                'endpoint' => "https://{$region}.your-objectstorage.com",
                'use_path_style' => true,
            ],
            default => null,
        };
    }

    /**
     * @return array<string, string> region code → human label
     */
    public static function regionsFor(string $type): array
    {
        return match ($type) {
            'b2' => [
                'us-west-001' => 'US West (Sacramento) — us-west-001',
                'us-west-002' => 'US West (Phoenix) — us-west-002',
                'us-east-005' => 'US East (Reston) — us-east-005',
                'eu-central-003' => 'EU Central (Amsterdam) — eu-central-003',
            ],
            'hetzner_objectstorage' => [
                'fsn1' => 'Falkenstein, Germany — fsn1',
                'nbg1' => 'Nuremberg, Germany — nbg1',
                'hel1' => 'Helsinki, Finland — hel1',
            ],
            default => [],
        };
    }
}
