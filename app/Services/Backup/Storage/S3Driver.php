<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

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
            // Adaptive retry tolerates intermittent 5xx from S3-compatible providers
            // (notably Hetzner Object Storage 504 GatewayTimeout under load).
            'retries' => [
                'mode' => 'adaptive',
                'max_attempts' => 5,
            ],
            // Explicit timeouts so a stalled connection fails fast and retries kick in.
            'http' => [
                'connect_timeout' => 30,
                'timeout' => 600,
            ],
        ];

        // Support custom endpoints (DigitalOcean Spaces, Backblaze B2, etc.)
        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
            $clientConfig['use_path_style_endpoint'] = $config['use_path_style'] ?? true;
        }

        $this->client = new S3Client($clientConfig);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $fileSize = filesize($localPath);
        $threshold = 100 * 1024 * 1024; // 100MB

        if ($fileSize > $threshold) {
            $this->multipartUpload($localPath, $remotePath);

            return;
        }

        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'SourceFile' => $localPath,
        ]);
    }

    /**
     * Upload a large file using S3 multipart upload (50MB parts, sequential).
     * Resumes from SDK upload state on MultipartUploadException so only failed
     * parts are re-uploaded rather than restarting from zero.
     */
    protected function multipartUpload(string $localPath, string $remotePath): void
    {
        $key = $this->fullPath($remotePath);
        $sizeMb = round(filesize($localPath) / 1048576, 1);
        Log::info("S3 multipart upload starting: {$key} ({$sizeMb} MB)");

        $uploader = new MultipartUploader($this->client, $localPath, [
            'bucket' => $this->bucket,
            'key' => $key,
            'part_size' => 50 * 1024 * 1024,
            'concurrency' => 1,
        ]);

        $maxResumes = 3;
        $attempt = 0;
        while (true) {
            try {
                $uploader->upload();
                Log::info("S3 multipart upload completed: {$key}");

                return;
            } catch (MultipartUploadException $e) {
                $attempt++;
                if ($attempt > $maxResumes) {
                    throw $e;
                }
                Log::warning(
                    "S3 multipart resume attempt {$attempt}/{$maxResumes} for {$key}: {$e->getMessage()}"
                );
                $uploader = new MultipartUploader($this->client, $localPath, [
                    'state' => $e->getState(),
                ]);
            }
        }
    }

    public function download(string $remotePath, string $localPath): void
    {
        $dir = dirname($localPath);
        if (! is_dir($dir)) {
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
            $prefix = rtrim($prefix, '/').'/';
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

    public function listRecursive(string $directory = ''): array
    {
        $prefix = $this->fullPath($directory);
        if ($prefix) {
            $prefix = rtrim($prefix, '/').'/';
        }

        $files = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                // No Delimiter — that's what makes it recursive
            ];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->client->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $object) {
                $files[] = [
                    'name' => basename($object['Key']),
                    'path' => $object['Key'],
                    'size' => $object['Size'],
                    'is_dir' => false,
                    'modified_at' => $object['LastModified']->format('Y-m-d H:i:s'),
                ];
            }

            $continuationToken = $result['NextContinuationToken'] ?? null;
        } while ($continuationToken);

        return $files;
    }

    public function listFolders(string $absolutePath = ''): array
    {
        return [];
    }

    public function uploadToAbsolutePath(string $localPath, string $absoluteRemotePath): void
    {
        $this->upload($localPath, $absoluteRemotePath);
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

    /**
     * Initiate a multipart upload and return the upload ID.
     */
    public function initiateMultipartUpload(string $remotePath): string
    {
        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);

        return $result['UploadId'];
    }

    /**
     * Generate presigned PUT URLs for each part of a multipart upload.
     *
     * @return array Array of ['part_number' => int, 'url' => string, 'start' => int, 'end' => int]
     */
    public function generatePresignedPartUrls(string $remotePath, string $uploadId, int $totalSize, int $partSize = 104857600): array
    {
        $parts = [];
        $partNumber = 1;
        $offset = 0;

        while ($offset < $totalSize) {
            $end = min($offset + $partSize, $totalSize);

            $command = $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $this->fullPath($remotePath),
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);

            $request = $this->client->createPresignedRequest($command, '+4 hours');

            $parts[] = [
                'part_number' => $partNumber,
                'url' => (string) $request->getUri(),
                'start' => $offset,
                'end' => $end,
            ];

            $offset = $end;
            $partNumber++;
        }

        return $parts;
    }

    /**
     * Complete a multipart upload with the ETags from each part.
     *
     * @param  array  $parts  Array of ['PartNumber' => int, 'ETag' => string]
     */
    public function completeMultipartUpload(string $remotePath, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => $parts,
            ],
        ]);
    }

    /**
     * Abort a multipart upload (cleanup on failure).
     */
    public function abortMultipartUpload(string $remotePath, string $uploadId): void
    {
        $this->client->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'UploadId' => $uploadId,
        ]);
    }

    protected function fullPath(string $relativePath): string
    {
        $path = ltrim($relativePath, '/');

        return $this->basePath ? $this->basePath.'/'.$path : $path;
    }
}
