<?php

namespace App\Services\Backup\Storage;

use Aws\S3\S3Client;
use RuntimeException;

class S3Driver implements StorageDriver
{
    protected S3Client $client;
    protected string $bucket;
    protected string $basePath;

    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'] ?? '';
        $this->basePath = trim($config['base_path'] ?? '', '/');

        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => decrypt($config['key'] ?? ''),
                'secret' => decrypt($config['secret'] ?? ''),
            ],
        ];

        // Support custom endpoints (DigitalOcean Spaces, Backblaze B2, etc.)
        if (!empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
            $clientConfig['use_path_style_endpoint'] = $config['use_path_style'] ?? true;
        }

        $this->client = new S3Client($clientConfig);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'SourceFile' => $localPath,
        ]);
    }

    public function download(string $remotePath, string $localPath): void
    {
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'SaveAs' => $localPath,
        ]);
    }

    public function delete(string $remotePath): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);
    }

    public function exists(string $remotePath): bool
    {
        return $this->client->doesObjectExistV2($this->bucket, $this->fullPath($remotePath));
    }

    public function size(string $remotePath): int
    {
        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);

        return (int) $result['ContentLength'];
    }

    public function list(string $directory = ''): array
    {
        $prefix = $this->fullPath($directory);
        if ($prefix) {
            $prefix = rtrim($prefix, '/') . '/';
        }

        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
        ]);

        $files = [];

        foreach ($result['Contents'] ?? [] as $object) {
            $files[] = [
                'name' => basename($object['Key']),
                'path' => $object['Key'],
                'size' => $object['Size'],
                'is_dir' => false,
                'modified_at' => $object['LastModified']->format('Y-m-d H:i:s'),
            ];
        }

        foreach ($result['CommonPrefixes'] ?? [] as $prefix) {
            $files[] = [
                'name' => basename(rtrim($prefix['Prefix'], '/')),
                'path' => $prefix['Prefix'],
                'size' => 0,
                'is_dir' => true,
                'modified_at' => null,
            ];
        }

        return $files;
    }

    public function test(): bool
    {
        $this->client->headBucket([
            'Bucket' => $this->bucket,
        ]);

        return true;
    }

    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);

        $request = $this->client->createPresignedRequest($command, "+{$expiresInMinutes} minutes");

        return (string) $request->getUri();
    }

    protected function fullPath(string $relativePath): string
    {
        $path = ltrim($relativePath, '/');
        return $this->basePath ? $this->basePath . '/' . $path : $path;
    }
}
