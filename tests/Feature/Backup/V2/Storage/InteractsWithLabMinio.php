<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Shared plumbing for hardened-uploader tests that run against the lab MinIO
 * (real S3 semantics, not a mock). The lab container reaches the spike MinIO at
 * http://spike-minio:9000 (see report: lab-php is joined to sam_spike_net).
 *
 * Credentials/endpoint are hard-wired to the lab MinIO on purpose: phpunit.xml
 * force-pins AWS_* empty so a stray host .env can never point these tests at real
 * object storage.
 */
trait InteractsWithLabMinio
{
    protected string $minioEndpoint = 'http://spike-minio:9000';

    protected string $minioKey = 'spikeadmin';

    protected string $minioSecret = 'spikeadmin123';

    protected string $bucket = 'backups';

    /** Unique per-test key prefix so parallel/leftover runs never collide. */
    protected string $testPrefix;

    protected function bootMinio(): void
    {
        $this->testPrefix = 'v2-hardened-tests/'.uniqid('', true);

        // When the test runner is on the host network (bin/test uses --network host)
        // rather than joined to sam_spike_net, point at the lab MinIO's host-published
        // port instead of the internal spike-minio:9000 alias.
        $endpoint = getenv('BACKUP_ENGINE_V2_LAB_S3_ENDPOINT');
        if (is_string($endpoint) && $endpoint !== '') {
            $this->minioEndpoint = $endpoint;
        }

        try {
            $this->minioClient()->headBucket(['Bucket' => $this->bucket]);
        } catch (Throwable $e) {
            $this->markTestSkipped('Lab MinIO not reachable at '.$this->minioEndpoint.': '.$e->getMessage());
        }
    }

    protected function minioClient(int $retries = 0): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $this->minioEndpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->minioKey, 'secret' => $this->minioSecret],
            // retries=0 by default so the uploader's OWN per-part retry logic is
            // what handles injected faults (not the SDK's built-in retryer).
            'retries' => $retries,
        ]);
    }

    /**
     * Middleware: throw a transient S3 error on the first uploadPart of $part.
     */
    protected function failFirstAttemptOnPart(S3Client $client, int $part): void
    {
        $seen = 0;
        $mw = function (callable $handler) use (&$seen, $part) {
            return function (CommandInterface $command, ?RequestInterface $request = null) use ($handler, &$seen, $part) {
                if ($command->getName() === 'UploadPart' && (int) $command['PartNumber'] === $part) {
                    $seen++;
                    if ($seen === 1) {
                        return Create::rejectionFor(new S3Exception(
                            'Injected transient error (503 SlowDown)',
                            $command,
                            ['code' => 'SlowDown']
                        ));
                    }
                }

                return $handler($command, $request);
            };
        };

        $client->getHandlerList()->appendSign($mw, 'inject-transient-fault');
    }

    /**
     * Middleware: corrupt the returned ETag for uploadPart of $part. $times = how
     * many attempts to corrupt (PHP_INT_MAX = always).
     */
    protected function corruptEtagOnPart(S3Client $client, int $part, int $times = PHP_INT_MAX): void
    {
        $seen = 0;
        $mw = function (callable $handler) use (&$seen, $part, $times) {
            return function (CommandInterface $command, ?RequestInterface $request = null) use ($handler, &$seen, $part, $times) {
                $promise = $handler($command, $request);

                if ($command->getName() === 'UploadPart' && (int) $command['PartNumber'] === $part) {
                    return $promise->then(function ($result) use (&$seen, $times) {
                        $seen++;
                        if ($seen <= $times) {
                            $result['ETag'] = '"deadbeefdeadbeefdeadbeefdeadbeef"';
                        }

                        return $result;
                    });
                }

                return $promise;
            };
        };

        $client->getHandlerList()->appendSign($mw, 'corrupt-etag');
    }

    protected function makeRandomFile(int $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v2mp_');
        $fh = fopen($path, 'wb');
        $written = 0;
        while ($written < $bytes) {
            $chunk = random_bytes((int) min(1048576, $bytes - $written));
            fwrite($fh, $chunk);
            $written += strlen($chunk);
        }
        fclose($fh);

        return $path;
    }

    protected function remoteSize(string $key): int
    {
        return (int) $this->minioClient()->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])['ContentLength'];
    }

    protected function remoteSha256(string $key): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'v2dl_');
        $this->minioClient()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SaveAs' => $tmp,
        ]);
        $hash = hash_file('sha256', $tmp);
        @unlink($tmp);

        return $hash;
    }

    /**
     * Count in-progress multipart uploads under $prefix. Filtered client-side
     * because MinIO's ListMultipartUploads ignores a server-side Prefix.
     */
    protected function inProgressUploadCount(string $prefix): int
    {
        $result = $this->minioClient()->listMultipartUploads(['Bucket' => $this->bucket]);

        return count(array_filter(
            $result['Uploads'] ?? [],
            static fn (array $u): bool => str_starts_with((string) $u['Key'], $prefix),
        ));
    }

    protected function cleanupMinio(): void
    {
        $client = $this->minioClient();

        // Abort any lingering multipart uploads under the test prefix.
        try {
            $uploads = $client->listMultipartUploads(['Bucket' => $this->bucket]);
            foreach ($uploads['Uploads'] ?? [] as $u) {
                if (! str_starts_with((string) $u['Key'], $this->testPrefix)) {
                    continue;
                }
                $client->abortMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $u['Key'],
                    'UploadId' => $u['UploadId'],
                ]);
            }
        } catch (Throwable) {
            // best effort
        }

        // Delete any objects under the test prefix.
        try {
            $objects = $client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $this->testPrefix,
            ]);
            foreach ($objects['Contents'] ?? [] as $o) {
                $client->deleteObject(['Bucket' => $this->bucket, 'Key' => $o['Key']]);
            }
        } catch (Throwable) {
            // best effort
        }
    }
}
