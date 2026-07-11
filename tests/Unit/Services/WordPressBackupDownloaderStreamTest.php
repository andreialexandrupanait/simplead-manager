<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WordPressBackupDownloader;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Backup chunk downloads must stream to disk with a hard cap — reading the
 * whole response into a string killed workers with a 256M memory fatal when
 * a connector returned more than the requested length.
 */
class WordPressBackupDownloaderStreamTest extends TestCase
{
    /** @return array{0: resource, 1: string} */
    private function tempHandle(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sam-test-chunk');
        $fh = fopen($path, 'wb');

        return [$fh, $path];
    }

    public function test_copies_a_normal_chunk_fully(): void
    {
        [$fh, $path] = $this->tempHandle();
        $data = str_repeat('x', 3 * 1024 * 1024); // 3MB

        $written = WordPressBackupDownloader::copyStreamCapped(Utils::streamFor($data), $fh, 4 * 1024 * 1024);
        fclose($fh);

        $this->assertSame(strlen($data), $written);
        $this->assertSame(strlen($data), filesize($path));
        unlink($path);
    }

    public function test_throws_when_response_exceeds_the_cap(): void
    {
        [$fh, $path] = $this->tempHandle();
        $oversized = str_repeat('x', 5 * 1024 * 1024); // 5MB against a 2MB cap

        try {
            WordPressBackupDownloader::copyStreamCapped(Utils::streamFor($oversized), $fh, 2 * 1024 * 1024);
            $this->fail('Expected RuntimeException for oversized response');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeded the expected chunk size', $e->getMessage());
        } finally {
            fclose($fh);
            unlink($path);
        }
    }

    public function test_empty_stream_writes_nothing(): void
    {
        [$fh, $path] = $this->tempHandle();

        $written = WordPressBackupDownloader::copyStreamCapped(Utils::streamFor(''), $fh, 1024);
        fclose($fh);

        $this->assertSame(0, $written);
        unlink($path);
    }
}
