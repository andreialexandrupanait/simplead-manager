<?php

declare(strict_types=1);

/**
 * Self-contained, deterministic fixture generator for the P2 full-backup E2E.
 *
 * Runs INSIDE spike-wp (the repo is not mounted there) so it must not require any
 * app code. The content of every file is a pure function of (seed, rel-path) via a
 * sha256 keystream — identical bytes on every run, and independently reproducible
 * by the Laravel test (Tests\Feature\Backup\V2\Support\LabBackupFixture) which
 * uses the SAME formula to compute expected sha256 per file WITHOUT copying any
 * ground-truth file across containers.
 *
 * Keep the SPEC + keystream here in lock-step with LabBackupFixture.php.
 *
 * Usage: php gen-fixture.php <abspath>   (abspath defaults to /var/www/html)
 */
const FIXTURE_SEED = 1337;
const FIXTURE_SUBDIR = 'wp-content/uploads/sam-backup-lab';

/**
 * The fixture spec: an ordered list of [relative-under-subdir, size-bytes].
 * Sizes chosen to force several 512 KiB file-chunks plus one >5 MiB chunk (so the
 * real full backup exercises a genuine multi-part upload), and to stay small/fast.
 *
 * @return list<array{0:string,1:int}>
 */
function fixture_spec(): array
{
    $dirs = ['alpha', 'beta', 'gamma/deep'];
    $sizes = [1000, 4096, 20000, 131072, 400000];
    $spec = [];
    for ($i = 0; $i < 40; $i++) {
        $dir = $dirs[$i % count($dirs)];
        $size = $sizes[$i % count($sizes)];
        $spec[] = ["{$dir}/file_{$i}.dat", $size];
    }
    // One file larger than the 5 MiB S3 part floor → its chunk is a true multi-part upload.
    $spec[] = ['gamma/deep/big.bin', 12 * 1024 * 1024];

    return $spec;
}

/**
 * Deterministic, incompressible content for (rel, size) hashed on the fly.
 *
 * @return string sha256 hex of the written file
 */
function write_fixture_file(string $absPath, string $rel, int $size): string
{
    $dir = dirname($absPath);
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ctx = hash_init('sha256');
    $fp = fopen($absPath, 'wb');
    $written = 0;
    $counter = 0;
    while ($written < $size) {
        $target = (int) min(1048576, $size - $written);
        $buf = '';
        while (strlen($buf) < $target) {
            $buf .= hash('sha256', FIXTURE_SEED.'|'.$rel.'|'.$counter, true);
            $counter++;
        }
        $chunk = substr($buf, 0, $target);
        fwrite($fp, $chunk);
        hash_update($ctx, $chunk);
        $written += $target;
    }
    fclose($fp);

    return hash_final($ctx);
}

$abspath = rtrim($argv[1] ?? '/var/www/html', '/');
$root = $abspath.'/'.FIXTURE_SUBDIR;

// Clean any prior fixture so the backup is deterministic run-to-run.
if (is_dir($root)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
@mkdir($root, 0775, true);

$groundTruth = [];
$total = 0;
foreach (fixture_spec() as [$rel, $size]) {
    $abs = $root.'/'.$rel;
    $sha = write_fixture_file($abs, $rel, $size);
    $relFromAbspath = FIXTURE_SUBDIR.'/'.$rel;
    $groundTruth[] = ['p' => $relFromAbspath, 's' => $size, 'sha256' => $sha];
    $total += $size;
}

// Human-readable ground truth (the test recomputes independently; this is for eyes).
file_put_contents($root.'/../sam-backup-lab-ground-truth.jsonl', implode("\n", array_map(
    static fn (array $r): string => (string) json_encode($r),
    $groundTruth
))."\n");

fwrite(STDOUT, sprintf(
    "fixture ok: %d files, %d bytes (%.2f MiB) under %s/%s\n",
    count($groundTruth),
    $total,
    $total / 1048576,
    FIXTURE_SUBDIR,
    ''
));
