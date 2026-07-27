<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

/**
 * The file fixture the P2 full-backup E2E backs up, defined by the SAME
 * deterministic (seed, rel-path) keystream as
 * spike/scratch/backup-v2-e2e/gen-fixture.php (which materialises the files inside
 * spike-wp). Because the content is a pure function of (seed, rel), this class can
 * recompute every file's ground-truth sha256 independently — the restore oracle
 * never needs a ground-truth file copied across containers.
 *
 * KEEP IN LOCK-STEP with gen-fixture.php (SEED, SUBDIR, spec, keystream).
 */
final class LabBackupFixture
{
    public const SEED = 1337;

    /** ABSPATH-relative directory the fixture lives under. */
    public const SUBDIR = 'wp-content/uploads/sam-backup-lab';

    /**
     * Ordered [rel-under-subdir, size-bytes]. Mirrors gen-fixture.php::fixture_spec().
     *
     * @return list<array{0:string,1:int}>
     */
    public static function spec(): array
    {
        $dirs = ['alpha', 'beta', 'gamma/deep'];
        $sizes = [1000, 4096, 20000, 131072, 400000];
        $spec = [];
        for ($i = 0; $i < 40; $i++) {
            $dir = $dirs[$i % count($dirs)];
            $size = $sizes[$i % count($sizes)];
            $spec[] = ["{$dir}/file_{$i}.dat", $size];
        }
        $spec[] = ['gamma/deep/big.bin', 12 * 1024 * 1024];

        return $spec;
    }

    /**
     * Ground truth keyed by ABSPATH-relative path (as it appears in the manifest):
     *   [ 'wp-content/uploads/sam-backup-lab/alpha/file_0.dat' => ['size'=>1000,'sha256'=>'...'], ... ]
     *
     * @return array<string, array{size:int, sha256:string}>
     */
    public static function groundTruth(): array
    {
        $out = [];
        foreach (self::spec() as [$rel, $size]) {
            $out[self::SUBDIR.'/'.$rel] = [
                'size' => $size,
                'sha256' => self::contentSha($rel, $size),
            ];
        }

        return $out;
    }

    /**
     * Recompute a fixture file's sha256 from (SEED, rel, size) — the same keystream
     * gen-fixture.php writes to disk.
     */
    public static function contentSha(string $rel, int $size): string
    {
        $ctx = hash_init('sha256');
        $written = 0;
        $counter = 0;
        while ($written < $size) {
            $target = (int) min(1048576, $size - $written);
            $buf = '';
            while (strlen($buf) < $target) {
                $buf .= hash('sha256', self::SEED.'|'.$rel.'|'.$counter, true);
                $counter++;
            }
            $chunk = substr($buf, 0, $target);
            hash_update($ctx, $chunk);
            $written += $target;
        }

        return hash_final($ctx);
    }

    /**
     * The include-only exclusion rule scoping a backup to just this fixture (so the
     * restore oracle has a closed, deterministic ground-truth set).
     *
     * @return list<array{type:string, value:string, action:string}>
     */
    public static function includeRule(): array
    {
        return [
            ['type' => 'glob', 'value' => self::SUBDIR.'/**', 'action' => 'include'],
        ];
    }
}
