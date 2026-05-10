<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Backup\BackupEncryptionService;
use Tests\TestCase;

/**
 * Guards the streaming AES-GCM encryption format and its backwards-compatible
 * fallback to the pre-v2 single-block format.
 */
class BackupEncryptionServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/backup_enc_test_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_small_file_roundtrip(): void
    {
        $path = $this->writeFile('small.txt', 'hello world');
        $enc = BackupEncryptionService::encryptFile($path);
        $dec = BackupEncryptionService::decryptFile($enc);

        $this->assertSame('hello world', file_get_contents($dec));
    }

    public function test_multi_chunk_file_roundtrip(): void
    {
        // 3.5 MB triggers 4 chunks (3 full + 1 partial)
        $payload = str_repeat('ABCDEFGH', 458752);
        $path = $this->writeFile('big.bin', $payload);
        $enc = BackupEncryptionService::encryptFile($path);
        $dec = BackupEncryptionService::decryptFile($enc);

        $this->assertSame($payload, file_get_contents($dec));
    }

    public function test_empty_file_roundtrip(): void
    {
        $path = $this->writeFile('empty.bin', '');
        $enc = BackupEncryptionService::encryptFile($path);
        $dec = BackupEncryptionService::decryptFile($enc);

        $this->assertSame('', file_get_contents($dec));
    }

    public function test_legacy_format_can_still_be_decrypted(): void
    {
        // Reproduce the pre-v2 format: [12 bytes IV][16 bytes tag][ciphertext]
        $key = hash('sha256', config('app.backup_encryption_key') ?: config('app.key'), true);
        $plaintext = 'legacy backup payload';
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        $legacyPath = $this->tmpDir.'/legacy.bin.enc';
        file_put_contents($legacyPath, $iv.$tag.$ct);

        $dec = BackupEncryptionService::decryptFile($legacyPath);

        $this->assertSame($plaintext, file_get_contents($dec));
    }

    public function test_streaming_keeps_memory_bounded(): void
    {
        // Write 20 MB; assert peak memory increase stays well under file size
        $path = $this->tmpDir.'/streaming.bin';
        $f = fopen($path, 'wb');
        $chunk = str_repeat('X', 1048576);
        for ($i = 0; $i < 20; $i++) {
            fwrite($f, $chunk);
        }
        fclose($f);

        gc_collect_cycles();
        $before = memory_get_peak_usage(true);
        $enc = BackupEncryptionService::encryptFile($path);
        $deltaMb = (memory_get_peak_usage(true) - $before) / 1048576;

        // Cleanup
        @unlink($enc);

        $this->assertLessThan(
            5,
            $deltaMb,
            "Encryption peak memory grew by {$deltaMb} MB for a 20 MB file — streaming is broken"
        );
    }

    public function test_corrupted_chunk_throws(): void
    {
        $path = $this->writeFile('corrupt.bin', str_repeat('A', 100));
        $enc = BackupEncryptionService::encryptFile($path);

        // Flip a byte in the ciphertext region (skip header: 4 magic + 1 ver + 8 nonce + 4 len + 16 tag = 33)
        $contents = file_get_contents($enc);
        $contents[40] = chr(ord($contents[40]) ^ 0xFF);
        file_put_contents($enc, $contents);

        $this->expectException(\RuntimeException::class);
        BackupEncryptionService::decryptFile($enc);
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
