<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

class BackupEncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_LENGTH = 16;

    /** Plaintext bytes per chunk. 1 MB keeps RAM bounded for arbitrarily large files. */
    private const CHUNK_SIZE = 1048576;

    /** Magic bytes that mark a v2 chunked-encrypted file. */
    private const V2_MAGIC = 'SAMC';

    /** Format version following the magic bytes. */
    private const V2_VERSION = 2;

    /** Random bytes used to derive per-chunk IVs (concatenated with a 4-byte counter to make a 12-byte IV). */
    private const V2_NONCE_BASE_LENGTH = 8;

    /**
     * Encrypt a file in place using AES-256-GCM. Streams in 1 MB chunks so memory
     * usage stays constant regardless of file size.
     *
     * Format (v2):
     *   [4 bytes magic "SAMC"]
     *   [1 byte version]
     *   [8 bytes nonce_base]
     *   then repeated until EOF:
     *     [4 bytes BE chunk_plaintext_len]
     *     [16 bytes GCM tag]
     *     [chunk_plaintext_len bytes ciphertext]
     *   [4 bytes 0x00000000 EOF marker]
     */
    public static function encryptFile(string $filePath): string
    {
        $key = self::getKey();
        $nonceBase = random_bytes(self::V2_NONCE_BASE_LENGTH);

        $input = fopen($filePath, 'rb');
        $outputPath = $filePath.'.enc';
        $output = fopen($outputPath, 'wb');

        if (! $input || ! $output) {
            throw new RuntimeException('Failed to open files for encryption');
        }

        try {
            fwrite($output, self::V2_MAGIC);
            fwrite($output, chr(self::V2_VERSION));
            fwrite($output, $nonceBase);

            $counter = 0;
            while (! feof($input)) {
                $plaintext = fread($input, self::CHUNK_SIZE);
                if ($plaintext === false || $plaintext === '') {
                    break;
                }

                $iv = $nonceBase.pack('N', $counter);
                $tag = '';
                $ciphertext = openssl_encrypt(
                    $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH
                );

                if ($ciphertext === false) {
                    throw new RuntimeException('Encryption failed: '.openssl_error_string());
                }

                fwrite($output, pack('N', strlen($plaintext)));
                fwrite($output, $tag);
                fwrite($output, $ciphertext);

                $counter++;
            }

            fwrite($output, pack('N', 0));
        } catch (\Throwable $e) {
            fclose($input);
            fclose($output);
            @unlink($outputPath);
            throw $e;
        }

        fclose($input);
        fclose($output);
        unlink($filePath);

        return $outputPath;
    }

    /**
     * Decrypt a .enc file. Auto-detects v2 (chunked) vs legacy (single-block) format.
     */
    public static function decryptFile(string $encryptedPath): string
    {
        $key = self::getKey();

        $input = fopen($encryptedPath, 'rb');
        if (! $input) {
            throw new RuntimeException('Failed to open encrypted file');
        }

        $maybeMagic = fread($input, 4);

        try {
            $outputPath = $maybeMagic === self::V2_MAGIC
                ? self::decryptChunked($input, $encryptedPath, $key)
                : self::decryptLegacy($input, $maybeMagic, $encryptedPath, $key);
        } finally {
            fclose($input);
        }

        unlink($encryptedPath);

        return $outputPath;
    }

    /**
     * Stream-decrypt a v2 chunked file. Magic bytes have already been consumed from $input.
     */
    private static function decryptChunked($input, string $encryptedPath, string $key): string
    {
        $versionByte = fread($input, 1);
        if ($versionByte === false || $versionByte === '') {
            throw new RuntimeException('Truncated encrypted file (version)');
        }
        $version = ord($versionByte);
        if ($version !== self::V2_VERSION) {
            throw new RuntimeException("Unsupported chunked encryption version: {$version}");
        }

        $nonceBase = fread($input, self::V2_NONCE_BASE_LENGTH);
        if (strlen($nonceBase) !== self::V2_NONCE_BASE_LENGTH) {
            throw new RuntimeException('Truncated encrypted file (nonce_base)');
        }

        $outputPath = self::stripEncExtension($encryptedPath);
        $output = fopen($outputPath, 'wb');
        if (! $output) {
            throw new RuntimeException('Failed to open output file');
        }

        try {
            $counter = 0;
            while (true) {
                $lenRaw = fread($input, 4);
                if ($lenRaw === false || strlen($lenRaw) !== 4) {
                    throw new RuntimeException('Truncated encrypted file (chunk length)');
                }
                $len = unpack('N', $lenRaw)[1];
                if ($len === 0) {
                    break;
                }
                if ($len > self::CHUNK_SIZE) {
                    throw new RuntimeException("Encrypted chunk length ({$len}) exceeds maximum");
                }

                $tag = fread($input, self::TAG_LENGTH);
                if (strlen($tag) !== self::TAG_LENGTH) {
                    throw new RuntimeException('Truncated encrypted file (tag)');
                }
                $ciphertext = fread($input, $len);
                if (strlen($ciphertext) !== $len) {
                    throw new RuntimeException('Truncated encrypted file (ciphertext)');
                }

                $iv = $nonceBase.pack('N', $counter);
                $plaintext = openssl_decrypt(
                    $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag
                );
                if ($plaintext === false) {
                    throw new RuntimeException("Decryption failed at chunk {$counter} — wrong key or corrupted file");
                }

                fwrite($output, $plaintext);
                $counter++;
            }
        } catch (\Throwable $e) {
            fclose($output);
            @unlink($outputPath);
            throw $e;
        }

        fclose($output);

        return $outputPath;
    }

    /**
     * Decrypt a legacy (pre-v2) file: single openssl_decrypt call. The first 4 bytes
     * (already read into $consumed) are part of the IV.
     */
    private static function decryptLegacy($input, string $consumed, string $encryptedPath, string $key): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $remaining = $ivLength - strlen($consumed);
        $iv = $remaining > 0 ? $consumed.fread($input, $remaining) : substr($consumed, 0, $ivLength);

        if (strlen($iv) !== $ivLength) {
            throw new RuntimeException('Truncated legacy encrypted file (IV)');
        }

        $tag = fread($input, self::TAG_LENGTH);
        if (strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Truncated legacy encrypted file (tag)');
        }

        $ciphertext = stream_get_contents($input);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (legacy format) — wrong key or corrupted file');
        }

        $outputPath = self::stripEncExtension($encryptedPath);
        file_put_contents($outputPath, $plaintext);

        return $outputPath;
    }

    private static function stripEncExtension(string $path): string
    {
        return str_ends_with($path, '.enc') ? substr($path, 0, -4) : $path.'.dec';
    }

    private static function getKey(): string
    {
        $key = config('app.backup_encryption_key') ?: config('app.key');

        // Derive a 32-byte key via SHA-256
        return hash('sha256', $key, true);
    }
}
