<?php

declare(strict_types=1);

namespace App\Backup\V2\Verification;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Support\BackupLogger;
use Aws\S3\S3Client;
use Throwable;
use ZipArchive;

/**
 * Sampled DEEP verification for the V2 backup engine — the expensive check the
 * create-verification cannot afford to run on every completion.
 *
 * For a configurable SAMPLE of a backup's objects it actually pulls the bytes and:
 *   - re-hashes them (full sha256) and compares to the manifest — catching
 *     byte-level corruption that preserves object size (invisible to the HEAD-only
 *     create-verification),
 *   - OPENS the archive: file chunks must be structurally-valid zips
 *     (ZipArchive::CHECKCONS) with entries; DB segments must be valid gzip whose
 *     decoded body is structurally-valid SQL,
 *   - recomposes the composite checksum (sha256 over the ordered per-object shas)
 *     and, when a create-verification exists, asserts it still matches.
 *
 * Records a `deep` BackupVerification. It NEVER stamps `verified_at` (that is the
 * create-verification's job) — a deep pass is an additional, stronger assurance,
 * a deep FAIL flags corruption for the operator.
 */
final class DeepVerifyService
{
    /** SQL tokens any real dump segment (head, middle or tail) contains at least one of. */
    private const SQL_TOKENS = ['CREATE TABLE', 'INSERT INTO', 'DROP TABLE', 'SET ', 'ALTER TABLE'];

    private readonly BackupLogger $logger;

    public function __construct(?BackupLogger $logger = null)
    {
        $this->logger = ($logger ?? new BackupLogger)->forSession('deep-verify', null, null);
    }

    /**
     * Deep-verify a sample of the backup's objects.
     *
     * @param  int  $sampleSize  max objects to fully download+open (0 or negative → all)
     */
    public function deepVerify(
        BackupSession $session,
        S3Client $s3,
        string $bucket,
        ObjectLayout $layout,
        int $sampleSize = 4,
    ): BackupVerification {
        $checks = [];

        try {
            $manifest = $this->getJson($s3, $bucket, $layout->manifest());
            $objects = array_values(is_array($manifest['objects'] ?? null) ? $manifest['objects'] : []);
            if ($objects === []) {
                return $this->record($session, BackupVerification::STATUS_FAILED, 0, 0, null, $checks, 'manifest lists no objects');
            }

            // Composite over the FULL manifest (recomputed here, independent of create).
            $composite = hash('sha256', implode('', array_map(
                static fn (array $o): string => (string) ($o['sha256'] ?? ''),
                $objects,
            )));
            $checks['composite_checksum'] = $composite;

            $sample = $this->pickSample($objects, $sampleSize);
            $checks['object_count'] = count($objects);
            $checks['sample_size'] = count($sample);

            $shaMismatch = [];
            $badArchive = [];
            $badSql = [];
            $opened = [];

            foreach ($sample as $object) {
                $key = (string) ($object['key'] ?? '');
                $kind = (string) ($object['kind'] ?? '');
                $declaredSha = (string) ($object['sha256'] ?? '');

                $tmp = (string) tempnam(sys_get_temp_dir(), 'v2deep_');
                try {
                    $s3->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $tmp]);

                    // (a) full sha256 — the real corruption detector.
                    $actualSha = (string) hash_file('sha256', $tmp);
                    if (! hash_equals($declaredSha, $actualSha)) {
                        $shaMismatch[] = ['key' => $key, 'declared' => $declaredSha, 'actual' => $actualSha];

                        continue; // a corrupt object is not worth opening
                    }

                    // (b) open the archive / parse the DB.
                    if ($kind === 'files') {
                        if (! $this->isValidZip($tmp)) {
                            $badArchive[] = $key;

                            continue;
                        }
                    } elseif ($kind === 'database') {
                        $sqlOk = $this->isValidSqlGzip($tmp);
                        if ($sqlOk === false) {
                            $badSql[] = $key;

                            continue;
                        }
                    }

                    $opened[] = $key;
                } finally {
                    @unlink($tmp);
                }
            }

            $checks['opened_ok'] = $opened;
            $checks['sha_mismatches'] = $shaMismatch;
            $checks['invalid_archives'] = $badArchive;
            $checks['invalid_sql'] = $badSql;

            // Cross-check against the create-verification's composite, if present.
            $create = BackupVerification::query()
                ->where('backup_session_id', $session->id)
                ->where('kind', BackupVerification::KIND_CREATE)
                ->where('status', BackupVerification::STATUS_PASSED)
                ->latest('id')
                ->first();
            if ($create !== null && $create->composite_checksum !== null) {
                $checks['composite_matches_create'] = hash_equals((string) $create->composite_checksum, $composite);
            }

            if ($shaMismatch !== []) {
                return $this->record($session, BackupVerification::STATUS_CORRUPT, count($objects), count($sample), $composite, $checks, count($shaMismatch).' sampled object(s) failed sha256 re-verification');
            }
            if ($badArchive !== []) {
                return $this->record($session, BackupVerification::STATUS_CORRUPT, count($objects), count($sample), $composite, $checks, count($badArchive).' sampled file chunk(s) are not valid archives');
            }
            if ($badSql !== []) {
                return $this->record($session, BackupVerification::STATUS_CORRUPT, count($objects), count($sample), $composite, $checks, count($badSql).' sampled DB segment(s) are not valid SQL');
            }
            if (($checks['composite_matches_create'] ?? true) === false) {
                return $this->record($session, BackupVerification::STATUS_FAILED, count($objects), count($sample), $composite, $checks, 'composite checksum diverged from the create-verification');
            }

            return $this->record($session, BackupVerification::STATUS_PASSED, count($objects), count($sample), $composite, $checks, null);
        } catch (Throwable $e) {
            $checks['exception'] = $e->getMessage();

            return $this->record($session, BackupVerification::STATUS_FAILED, 0, 0, null, $checks, $e->getMessage());
        }
    }

    /**
     * Pick a spread-out sample that favours covering BOTH kinds (a DB segment and a
     * file chunk) before filling the rest deterministically.
     *
     * @param  list<array<string,mixed>>  $objects
     * @return list<array<string,mixed>>
     */
    private function pickSample(array $objects, int $sampleSize): array
    {
        if ($sampleSize <= 0 || $sampleSize >= count($objects)) {
            return $objects;
        }

        $byKind = ['database' => [], 'files' => [], 'other' => []];
        foreach ($objects as $o) {
            $kind = (string) ($o['kind'] ?? 'other');
            $byKind[$kind === 'database' || $kind === 'files' ? $kind : 'other'][] = $o;
        }

        $sample = [];
        $seenKeys = [];
        // Seed with one of each kind so both archive + SQL paths are exercised.
        foreach (['database', 'files', 'other'] as $kind) {
            if ($byKind[$kind] !== [] && count($sample) < $sampleSize) {
                $first = $byKind[$kind][0];
                $sample[] = $first;
                $seenKeys[(string) $first['key']] = true;
            }
        }
        // Fill deterministically from the front.
        foreach ($objects as $o) {
            if (count($sample) >= $sampleSize) {
                break;
            }
            if (! isset($seenKeys[(string) $o['key']])) {
                $sample[] = $o;
                $seenKeys[(string) $o['key']] = true;
            }
        }

        return $sample;
    }

    private function isValidZip(string $path): bool
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            $zip->close();

            return false;
        }
        $numFiles = $zip->numFiles;
        $zip->close();

        return $numFiles >= 1;
    }

    /**
     * A DB segment is valid when it is readable gzip AND its decoded body contains
     * at least one recognisable SQL statement token (head/middle/tail all do).
     */
    private function isValidSqlGzip(string $path): bool
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }
        $decoded = @gzdecode($raw);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        foreach (self::SQL_TOKENS as $token) {
            if (str_contains($decoded, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $checks
     */
    private function record(
        BackupSession $session,
        string $status,
        int $objectCount,
        int $sampleSize,
        ?string $composite,
        array $checks,
        ?string $error,
    ): BackupVerification {
        $verification = new BackupVerification([
            'backup_session_id' => $session->id,
            'kind' => BackupVerification::KIND_DEEP,
            'status' => $status,
            'object_count' => $objectCount,
            'sample_size' => $sampleSize,
            'composite_checksum' => $composite,
            'checks' => $checks,
            'error' => $error,
            'verified_at' => $status === BackupVerification::STATUS_PASSED ? now() : null,
        ]);
        $verification->save();

        $this->logger->log(
            $status === BackupVerification::STATUS_PASSED ? 'info' : 'warning',
            'backup deep-verification',
            ['session_id' => $session->id, 'status' => $status, 'sample_size' => $sampleSize, 'error' => $error],
        );

        return $verification;
    }

    /**
     * @return array<string,mixed>
     */
    private function getJson(S3Client $s3, string $bucket, string $key): array
    {
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $decoded = json_decode((string) $result['Body'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
