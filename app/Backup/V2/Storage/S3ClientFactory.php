<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use Aws\S3\S3Client;

/**
 * Builds the Aws\S3\S3Client the V2 backup engine uploads through.
 *
 * LAB: config('backup_v2.lab_s3.*') points at the spike MinIO (path-style,
 * static creds). This is what the lab tests/harness use.
 *
 * PRODUCTION (TODO — P2/P3): the real client must be built from the site's
 * StorageDestination, decrypting endpoint/key/secret/region/bucket exactly as
 * the existing V1 S3 driver does (App\Services\Backup\Storage\S3Driver). That
 * path is deliberately NOT wired here yet — with config('backup_v2.enabled')
 * false the engine never runs in production, so only the lab factory is needed
 * to prove the end-to-end flow. See docs/backup/TARGET-ARCHITECTURE.md.
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
        return new S3Client([
            'version' => 'latest',
            'region' => $this->config['region'],
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => $this->config['use_path_style_endpoint'],
            'credentials' => [
                'key' => $this->config['key'],
                'secret' => $this->config['secret'],
            ],
            'retries' => $retries,
        ]);
    }
}
