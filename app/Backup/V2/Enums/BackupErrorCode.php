<?php

declare(strict_types=1);

namespace App\Backup\V2\Enums;

/**
 * Explicit, typed error codes for V2 backup/restore failures.
 *
 * Replaces the silent `@`/`catch {}` swallowing called out in
 * docs/backup/TARGET-ARCHITECTURE.md ("What we reuse vs replace"). Every failure
 * surfaces one of these so the manager (and the UI) can reason about the cause
 * and pick a recovery strategy (transient → retry_wait, permanent → failed).
 */
enum BackupErrorCode: string
{
    case ObjectMissing = 'object_missing';
    case ChecksumMismatch = 'checksum_mismatch';
    case DiskFull = 'disk_full';
    case HostTimeout = 'host_timeout';
    case ManifestInvalid = 'manifest_invalid';
    case SnapshotUnavailable = 'snapshot_unavailable';
    case UploadFailed = 'upload_failed';
    case CallbackLost = 'callback_lost';

    /**
     * Whether this class of error is generally worth retrying (transient) vs a
     * hard failure. Advisory only — the caller decides the actual policy.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::HostTimeout, self::UploadFailed, self::CallbackLost, self::SnapshotUnavailable => true,
            self::ObjectMissing, self::ChecksumMismatch, self::DiskFull, self::ManifestInvalid => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ObjectMissing => 'Object missing in storage',
            self::ChecksumMismatch => 'Checksum mismatch',
            self::DiskFull => 'Disk full',
            self::HostTimeout => 'Host timeout',
            self::ManifestInvalid => 'Manifest invalid',
            self::SnapshotUnavailable => 'Snapshot unavailable',
            self::UploadFailed => 'Upload failed',
            self::CallbackLost => 'Callback lost',
        };
    }
}
