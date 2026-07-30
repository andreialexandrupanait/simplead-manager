<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupErrorCode;
use RuntimeException;
use Throwable;

/**
 * A definitive multipart-upload failure (all per-part retries exhausted, or a
 * non-retryable condition). Carries a typed BackupErrorCode so the orchestrating
 * FSM can pick retry_wait vs failed vs corrupt.
 */
final class MultipartUploadException extends RuntimeException
{
    public function __construct(
        public readonly BackupErrorCode $errorCode,
        string $message,
        public readonly ?string $objectKey = null,
        public readonly ?int $partNumber = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function checksumMismatch(string $key, int $partNumber, string $expected, string $actual): self
    {
        return new self(
            BackupErrorCode::ChecksumMismatch,
            sprintf('Part %d of %s failed checksum: expected ETag %s, got %s', $partNumber, $key, $expected, $actual),
            $key,
            $partNumber,
        );
    }

    public static function partFailed(string $key, int $partNumber, int $attempts, Throwable $previous): self
    {
        return new self(
            BackupErrorCode::UploadFailed,
            sprintf('Part %d of %s failed after %d attempt(s): %s', $partNumber, $key, $attempts, $previous->getMessage()),
            $key,
            $partNumber,
            $previous,
        );
    }
}
