<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Aws\S3\S3Client;
use InvalidArgumentException;

/**
 * Builds the Aws\S3\S3Client the V2 backup engine uploads through.
 *
 * LAB: config('backup_v2.lab_s3.*') points at the spike MinIO (path-style,
 * static creds). This is what the lab tests/harness use.
 *
 * PRODUCTION: ::forDestination() builds the client from a site's real
 * StorageDestination, decrypting endpoint/key/secret/region/bucket EXACTLY as the
 * existing V1 S3 driver does (App\Services\Backup\Storage\S3Driver) — no secret
 * ever duplicated in code, only the same decrypt() the V1 driver already trusts.
 * It stays inert until a gated job (see BackupV2Gate) resolves it for a
 * whitelisted site. See docs/backup/TARGET-ARCHITECTURE.md.
 */
final class S3ClientFactory
{
    /**
     * @param  array{endpoint:string,region:string,key:string,secret:string,bucket:string,use_path_style_endpoint:bool}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Lab MinIO client from config('backup_v2.lab_s3.*').
     */
    public static function lab(): self
    {
        /** @var array<string, mixed> $c */
        $c = (array) config('backup_v2.lab_s3', []);

        return new self([
            'endpoint' => (string) ($c['endpoint'] ?? 'http://spike-minio:9000'),
            'region' => (string) ($c['region'] ?? 'us-east-1'),
            'key' => (string) ($c['key'] ?? 'spikeadmin'),
            'secret' => (string) ($c['secret'] ?? 'spikeadmin123'),
            'bucket' => (string) ($c['bucket'] ?? 'backups'),
            'use_path_style_endpoint' => (bool) ($c['use_path_style_endpoint'] ?? true),
        ]);
    }

    /**
     * Production client from a site's real StorageDestination.
     *
     * The destination's `config` jsonb carries the SAME shape the V1 S3Driver
     * consumes: Laravel-ENCRYPTED `key`/`secret`, plus `region`, optional custom
     * `endpoint` (+ `use_path_style`) and `bucket`. Credentials are decrypted with
     * the exact `decrypt()` the V1 driver uses — this factory never stores or
     * re-derives a secret of its own. When no explicit endpoint is set, the
     * S3-compatible provider endpoint is resolved through the same
     * StorageFactory::endpointFor() map as V1 (Hetzner/Backblaze); a plain AWS `s3`
     * destination resolves to no custom endpoint (region-native).
     *
     * @throws InvalidArgumentException when the destination is not an S3-compatible type
     */
    public static function forDestination(StorageDestination $destination): self
    {
        if (! in_array($destination->type, ['s3', 'b2', 'hetzner_objectstorage'], true)) {
            throw new InvalidArgumentException(
                "StorageDestination {$destination->id} type '{$destination->type}' is not S3-compatible."
            );
        }

        /** @var array<string, mixed> $config */
        $config = $destination->config ?? [];

        $region = (string) ($config['region'] ?? 'us-east-1');

        // Custom endpoint takes precedence (as V1 S3Driver); otherwise resolve the
        // provider endpoint from the SAME region→endpoint map V1 uses.
        $endpoint = (string) ($config['endpoint'] ?? '');
        $usePathStyle = (bool) ($config['use_path_style'] ?? true);
        if ($endpoint === '') {
            $resolved = StorageFactory::endpointFor($destination->type, $region);
            if ($resolved !== null) {
                $endpoint = $resolved['endpoint'];
                $usePathStyle = $resolved['use_path_style'];
            }
        }

        return new self([
            // decrypt() EXACTLY as App\Services\Backup\Storage\S3Driver — the single
            // source of truth for how these credentials are stored.
            'key' => (string) decrypt((string) ($config['key'] ?? '')),
            'secret' => (string) decrypt((string) ($config['secret'] ?? '')),
            'region' => $region,
            'endpoint' => $endpoint,
            'bucket' => (string) ($config['bucket'] ?? ''),
            'use_path_style_endpoint' => $usePathStyle,
        ]);
    }

    /**
     * The target bucket for this destination.
     */
    public function bucket(): string
    {
        return $this->config['bucket'];
    }

    /**
     * Build the SDK client.
     *
     * @param  int  $retries  SDK-level retries. Default 0 so the
     *                        HardenedMultipartUploader's own per-part retry logic
     *                        is the single source of retry truth.
     */
    public function client(int $retries = 0): S3Client
    {
        $args = [
            'version' => 'latest',
            'region' => $this->config['region'],
            'credentials' => [
                'key' => $this->config['key'],
                'secret' => $this->config['secret'],
            ],
            'retries' => $retries,
        ];

        // Custom endpoint only for S3-compatible providers (MinIO, Hetzner, B2). A
        // plain AWS S3 destination has an empty endpoint and stays region-native —
        // matching V1 S3Driver, which only sets endpoint when one is configured.
        if ($this->config['endpoint'] !== '') {
            $args['endpoint'] = $this->config['endpoint'];
            $args['use_path_style_endpoint'] = $this->config['use_path_style_endpoint'];
        }

        return new S3Client($args);
    }
}
