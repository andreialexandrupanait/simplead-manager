<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Services\WordPress\WordPressHttpClient;
use App\Services\WordPressBackupDownloader;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class WordPressBackupDownloaderTest extends TestCase
{
    private WordPressApiServiceInterface $api;

    private WordPressHttpClient $http;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(WordPressApiServiceInterface::class);
        $this->http = $this->createMock(WordPressHttpClient::class);
        $this->tempDir = sys_get_temp_dir().'/test-wp-downloader-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function makeResponse(int $status, array $json): Response
    {
        $psrResponse = new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($json));

        return new Response($psrResponse);
    }

    private function makeRawResponse(int $status, string $body): Response
    {
        $psrResponse = new \GuzzleHttp\Psr7\Response($status, [], $body);

        return new Response($psrResponse);
    }

    public function test_chunked_download_falls_back_to_sync_when_init_fails(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';
        $fileContent = str_repeat('x', 100);
        $checksum = hash('sha256', $fileContent);

        // Init fails → fallback to sync prepare
        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $data = []) use ($checksum, $fileContent) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(404, ['error' => 'Not found']);
                }
                if ($endpoint === '/backup/prepare') {
                    return $this->makeResponse(200, [
                        'success' => true,
                        'token' => 'test-token',
                        'size' => strlen($fileContent),
                        'checksum' => $checksum,
                    ]);
                }
                if ($endpoint === '/backup/cleanup') {
                    return $this->makeResponse(200, ['success' => true]);
                }

                return $this->makeResponse(200, []);
            });

        $this->api->method('requestRaw')
            ->willReturn($this->makeRawResponse(200, $fileContent));

        $downloader = new WordPressBackupDownloader($this->api, $this->http);
        $downloader->chunkedDownload('database', $saveTo);

        $this->assertFileExists($saveTo);
        $this->assertSame($fileContent, file_get_contents($saveTo));
    }

    public function test_sync_prepare_failure_throws(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';

        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(404, []);
                }
                if ($endpoint === '/backup/prepare') {
                    return $this->makeResponse(200, [
                        'success' => false,
                        'error' => ['message' => 'Disk full'],
                    ]);
                }

                return $this->makeResponse(200, []);
            });

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Disk full/');

        $downloader->chunkedDownload('database', $saveTo);
    }

    public function test_checksum_mismatch_deletes_file_and_throws(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';
        $fileContent = 'test-data';
        $wrongChecksum = 'wrong-checksum-value';

        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint) use ($wrongChecksum, $fileContent) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(404, []);
                }
                if ($endpoint === '/backup/prepare') {
                    return $this->makeResponse(200, [
                        'success' => true,
                        'token' => 'test-token',
                        'size' => strlen($fileContent),
                        'checksum' => $wrongChecksum,
                    ]);
                }

                return $this->makeResponse(200, []);
            });

        $this->api->method('requestRaw')
            ->willReturn($this->makeRawResponse(200, $fileContent));

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Checksum mismatch/');

        try {
            $downloader->chunkedDownload('database', $saveTo);
        } catch (\RuntimeException $e) {
            $this->assertFileDoesNotExist($saveTo);
            throw $e;
        }
    }

    public function test_chunked_download_files_as_chunks_throws_on_init_failure(): void
    {
        $this->api->method('request')
            ->willReturn($this->makeResponse(500, ['error' => 'Internal error']));

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);

        $downloader->chunkedDownloadFilesAsChunks($this->tempDir.'/files.zip');
    }

    public function test_chunked_download_files_as_chunks_throws_on_non_success(): void
    {
        $this->api->method('request')
            ->willReturn($this->makeResponse(200, ['success' => false]));

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-success/');

        $downloader->chunkedDownloadFilesAsChunks($this->tempDir.'/files.zip');
    }

    public function test_progress_callback_is_called(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';
        $fileContent = 'data';
        $checksum = hash('sha256', $fileContent);

        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint) use ($checksum, $fileContent) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(404, []);
                }
                if ($endpoint === '/backup/prepare') {
                    return $this->makeResponse(200, [
                        'success' => true,
                        'token' => 'tok',
                        'size' => strlen($fileContent),
                        'checksum' => $checksum,
                    ]);
                }
                if ($endpoint === '/backup/cleanup') {
                    return $this->makeResponse(200, []);
                }

                return $this->makeResponse(200, []);
            });

        $this->api->method('requestRaw')
            ->willReturn($this->makeRawResponse(200, $fileContent));

        $progressCalls = [];
        $downloader = new WordPressBackupDownloader($this->api, $this->http);
        $downloader->chunkedDownload('database', $saveTo, function ($offset, $total) use (&$progressCalls) {
            $progressCalls[] = [$offset, $total];
        });

        $this->assertNotEmpty($progressCalls);
        $last = end($progressCalls);
        $this->assertSame($last[0], $last[1]); // final call: offset === total
    }

    public function test_chunked_init_error_propagates_if_init_was_available(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';
        $callCount = 0;

        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $data = []) use (&$callCount) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(200, [
                        'success' => true,
                        'token' => 'tok',
                        'total_chunks' => 3,
                        'type' => 'database',
                    ]);
                }
                if ($endpoint === '/backup/prepare-chunk-exec') {
                    $callCount++;

                    // Fail on first chunk exec
                    return $this->makeResponse(200, ['success' => false, 'error' => ['message' => 'Disk error']]);
                }

                return $this->makeResponse(200, []);
            });

        $this->api->method('setBackupMode');

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Chunk 0 exec failed/');

        $downloader->chunkedDownload('database', $saveTo);
    }

    public function test_empty_backup_file_throws_after_chunked_download(): void
    {
        $saveTo = $this->tempDir.'/backup.sql.gz';

        $this->api->method('request')
            ->willReturnCallback(function (string $method, string $endpoint, array $data = []) {
                if ($endpoint === '/backup/prepare-init') {
                    return $this->makeResponse(200, [
                        'success' => true,
                        'token' => 'tok',
                        'total_chunks' => 1,
                        'type' => 'database',
                    ]);
                }
                if ($endpoint === '/backup/prepare-chunk-exec') {
                    return $this->makeResponse(200, ['success' => true, 'chunk_size' => 100]);
                }
                if ($endpoint === '/backup/cleanup') {
                    return $this->makeResponse(200, []);
                }

                return $this->makeResponse(200, []);
            });

        $this->api->method('setBackupMode');
        $this->api->method('resetThrottle');
        // streamDownloadTo writes an empty file
        $this->api->method('streamDownloadTo')
            ->willReturnCallback(function ($endpoint, $data, $savePath) {
                file_put_contents($savePath, '');
            });

        $downloader = new WordPressBackupDownloader($this->api, $this->http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty/');

        $downloader->chunkedDownload('database', $saveTo);
    }
}
