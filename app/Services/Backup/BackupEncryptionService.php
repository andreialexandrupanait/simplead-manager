<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

class BackupEncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_LENGTH = 16;

    /**
     * Encrypt a file in place using AES-256-GCM.
     * Appends .enc extension and removes original.
     */
    public static function encryptFile(string $filePath): string
    {
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $input = fopen($filePath, 'rb');
        $outputPath = $filePath.'.enc';
        $output = fopen($outputPath, 'wb');

        if (! $input || ! $output) {
            throw new RuntimeException('Failed to open files for encryption');
        }

        $plaintext = stream_get_contents($input);
        fclose($input);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($ciphertext === false) {
            fclose($output);
            @unlink($outputPath);
            throw new RuntimeException('Encryption failed: '.openssl_error_string());
        }

        // Format: [16 bytes IV][16 bytes tag][ciphertext]
        fwrite($output, $iv);
        fwrite($output, $tag);
        fwrite($output, $ciphertext);
        fclose($output);

        unlink($filePath);

        return $outputPath;
    }

    /**
     * Decrypt a .enc file. Removes .enc extension and deletes encrypted file.
     */
    public static function decryptFile(string $encryptedPath): string
    {
        $key = self::getKey();

        $input = fopen($encryptedPath, 'rb');
        if (! $input) {
            throw new RuntimeException('Failed to open encrypted file');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = fread($input, $ivLength);
        $tag = fread($input, self::TAG_LENGTH);
        $ciphertext = stream_get_contents($input);
        fclose($input);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — wrong key or corrupted file');
        }

        // Write decrypted file (remove .enc extension)
        $outputPath = str_ends_with($encryptedPath, '.enc')
            ? substr($encryptedPath, 0, -4)
            : $encryptedPath.'.dec';

        file_put_contents($outputPath, $plaintext);
        unlink($encryptedPath);

        return $outputPath;
    }

    private static function getKey(): string
    {
        $key = config('app.backup_encryption_key') ?: config('app.key');

        // Derive a 32-byte key via SHA-256
        return hash('sha256', $key, true);
    }
}
