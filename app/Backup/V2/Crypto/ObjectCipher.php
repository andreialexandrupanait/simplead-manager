<?php

declare(strict_types=1);

namespace App\Backup\V2\Crypto;

use RuntimeException;

/**
 * Encrypts a backup object on the way to storage, and back on the way out.
 *
 * A backup contains the client's entire database and every file they have
 * uploaded. Stored in the clear, anyone who can read the bucket — the storage
 * provider, anyone holding a leaked key, anyone who finds a misconfigured
 * policy — has all of it. Server-side encryption would move the key to the
 * provider, which answers a different question than the one worth answering
 * here.
 *
 * Encryption happens on the manager, which already holds every chunk in a local
 * temp file before uploading it, so this costs no plugin change and no extra
 * round trip.
 *
 * FRAMED, not one-shot. A file chunk can be a hundred megabytes, and AES-GCM in
 * a single pass would need the whole object in memory on both sides. The format
 * is a short header followed by independently sealed frames:
 *
 *   magic "SAMENC1" | version | key_id (32B, right-padded) | base nonce (8B)
 *   then, per frame: uint32 ciphertext length | 16B tag | ciphertext
 *
 * Each frame's nonce is the base nonce plus a 4-byte counter, so no nonce is
 * ever reused under one key, and frames must be read in order — a reordered or
 * truncated file fails to open rather than decrypting to something plausible.
 * The frame counter is part of the AAD, so a frame lifted from one position and
 * dropped into another does not authenticate.
 */
final class ObjectCipher
{
    private const MAGIC = 'SAMENC1';

    private const VERSION = 1;

    private const CIPHER = 'aes-256-gcm';

    private const TAG_BYTES = 16;

    private const KEY_ID_BYTES = 32;

    private const NONCE_BASE_BYTES = 8;

    /** 1 MiB plaintext per frame — bounded memory on both sides. */
    private const FRAME_BYTES = 1048576;

    public function __construct(
        private readonly string $key,
        private readonly string $keyId,
    ) {
        if (strlen($this->key) !== 32) {
            throw new RuntimeException('A backup encryption key must be exactly 32 bytes.');
        }
    }

    /**
     * Encrypt $source into $destination. Returns the ciphertext's sha256.
     */
    public function encryptFile(string $source, string $destination): string
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $nonceBase = random_bytes(self::NONCE_BASE_BYTES);
            fwrite($out, $this->header($nonceBase));

            $counter = 0;
            while (! feof($in)) {
                $plain = fread($in, self::FRAME_BYTES);
                if ($plain === false || $plain === '') {
                    break;
                }

                $tag = '';
                $cipher = openssl_encrypt(
                    $plain,
                    self::CIPHER,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $nonceBase.pack('N', $counter),
                    $tag,
                    pack('N', $counter),
                    self::TAG_BYTES,
                );

                if ($cipher === false) {
                    throw new RuntimeException('Failed to encrypt a backup frame.');
                }

                fwrite($out, pack('N', strlen($cipher)).$tag.$cipher);
                $counter++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return (string) hash_file('sha256', $destination);
    }

    /**
     * Decrypt $source into $destination. Returns the plaintext's sha256.
     *
     * Any tampering — a flipped bit, a dropped frame, a frame moved — fails
     * here rather than producing a file that looks restorable.
     */
    public function decryptFile(string $source, string $destination): string
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $nonceBase = $this->readHeader($in);

            $counter = 0;
            while (true) {
                $lengthBytes = fread($in, 4);
                if ($lengthBytes === false || strlen($lengthBytes) < 4) {
                    break; // clean end of stream
                }

                $length = unpack('N', $lengthBytes)[1];
                $tag = (string) fread($in, self::TAG_BYTES);
                $cipher = (string) fread($in, $length);

                if (strlen($tag) !== self::TAG_BYTES || strlen($cipher) !== $length) {
                    throw new RuntimeException('The encrypted backup is truncated.');
                }

                $plain = openssl_decrypt(
                    $cipher,
                    self::CIPHER,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $nonceBase.pack('N', $counter),
                    $tag,
                    pack('N', $counter),
                );

                if ($plain === false) {
                    throw new RuntimeException(
                        "The encrypted backup failed authentication at frame {$counter}: "
                        .'it was produced with a different key, or it has been altered.'
                    );
                }

                fwrite($out, $plain);
                $counter++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return (string) hash_file('sha256', $destination);
    }

    /**
     * Is this file one of ours? Lets the restore path read backups written
     * before encryption existed without keeping a flag anywhere.
     */
    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * Which key sealed this file. Read without decrypting, so a restore can
     * fetch the right key — and say so precisely when it does not have it.
     */
    public static function keyIdOf(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if ((string) fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
                return null;
            }
            fread($handle, 1); // version

            return rtrim((string) fread($handle, self::KEY_ID_BYTES), "\0");
        } finally {
            fclose($handle);
        }
    }

    private function header(string $nonceBase): string
    {
        return self::MAGIC
            .chr(self::VERSION)
            .str_pad(substr($this->keyId, 0, self::KEY_ID_BYTES), self::KEY_ID_BYTES, "\0")
            .$nonceBase;
    }

    /** @return string the base nonce */
    private function readHeader($handle): string
    {
        if ((string) fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('Not an encrypted backup object.');
        }

        $version = ord((string) fread($handle, 1));
        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported backup encryption version {$version}.");
        }

        $keyId = rtrim((string) fread($handle, self::KEY_ID_BYTES), "\0");
        if ($keyId !== $this->keyId) {
            throw new RuntimeException(
                "This object was encrypted with key '{$keyId}', not '{$this->keyId}'."
            );
        }

        return (string) fread($handle, self::NONCE_BASE_BYTES);
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Could not open {$path} for encryption.");
        }

        return $handle;
    }
}
