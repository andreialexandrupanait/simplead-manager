<?php

declare(strict_types=1);

/**
 * Deterministic, seeded file-tree generator for the backup spike.
 *
 * Every generated file's content is a pure function of (seed, path), so re-running
 * with the same seed yields byte-identical trees. Each file's sha256 is recorded to a
 * ground-truth JSONL manifest — the oracle used to verify that a restore reproduces
 * content exactly.
 *
 * Usage:
 *   php gen-files.php --profile=small|medium|large --seed=42 \
 *     --root=/var/www/html/wp-content/uploads/spike --out=/tmp/ground-truth.jsonl [--sparse]
 *
 * Size distribution is bimodal (many tiny files + a few big media), mirroring real WP
 * uploads. The huge-file tail (1–5 GB) is written via a streamed seeded keystream so
 * memory stays flat regardless of file size.
 */

$opts = getopt('', ['profile:', 'seed::', 'root:', 'out:', 'sparse::']);
$profile = $opts['profile'] ?? 'small';
$seed = (int) ($opts['seed'] ?? 42);
$root = rtrim($opts['root'] ?? '/tmp/spike-files', '/');
$out = $opts['out'] ?? '/tmp/ground-truth.jsonl';
$sparse = array_key_exists('sparse', $opts);

$profiles = [
    // files, small-file byte buckets, big-file tail [count => size]
    'small'  => ['files' => 5000,   'big' => []],
    'medium' => ['files' => 100000, 'big' => [200 * 1048576 => 5]],
    'large'  => ['files' => 500000, 'big' => [1073741824 => 3, 3 * 1073741824 => 2]],
    // Targeted "large" for the single-big-file round (NEXT-STEPS): a realistic spread
    // of incompressible small files PLUS one 2 GB single file, larger than any chunk
    // threshold, so it becomes ONE chunk → temp = file size, one 2 GB multipart.
    'large-single' => ['files' => 8000, 'big' => [2 * 1073741824 => 1]],
];
if (! isset($profiles[$profile])) {
    fwrite(STDERR, "unknown profile: $profile\n");
    exit(2);
}
$cfg = $profiles[$profile];

$buckets = [1024, 8192, 65536, 524288]; // 1K, 8K, 64K, 512K
$dirs = ['plugins', 'themes', 'mu-plugins', 'uploads/2024/01', 'uploads/2024/06', 'uploads/2025/01', 'custom'];

@mkdir($root, 0775, true);
foreach ($dirs as $d) {
    @mkdir("$root/$d", 0775, true);
}

$fh = fopen($out, 'wb');
$totalBytes = 0;
$count = 0;

/** Deterministic per-path PRNG. */
function rngFor(int $seed, string $path): int
{
    return (int) hexdec(substr(hash('sha256', $seed . '|' . $path), 0, 12));
}

/**
 * Fill a stream with truly-incompressible, deterministic content and hash it.
 *
 * Every 32-byte segment is a FRESH sha256 of (seed | path | counter), so no block
 * ever repeats — the output has full entropy (gzip/deflate cannot shrink it) while
 * staying a pure function of (seed, path) and memory-flat (≤1 MB working buffer).
 */
function fillIncompressible($fp, $ctx, int $size, int $seed, string $path): void
{
    $written = 0;
    $counter = 0;
    while ($written < $size) {
        $target = (int) min(1048576, $size - $written); // build ≤1 MB at a time
        $buf = '';
        while (strlen($buf) < $target) {
            $buf .= hash('sha256', $seed . '|' . $path . '|' . $counter, true); // 32 fresh bytes
            $counter++;
        }
        $chunk = substr($buf, 0, $target);
        fwrite($fp, $chunk);
        hash_update($ctx, $chunk);
        $written += $target;
    }
}

/** Write a small file with deterministic, incompressible content; return sha256. */
function writeSmall(string $path, int $size, int $seed): string
{
    $ctx = hash_init('sha256');
    $fp = fopen($path, 'wb');
    fillIncompressible($fp, $ctx, $size, $seed, $path);
    fclose($fp);

    return hash_final($ctx);
}

/** Stream a huge file via the same seeded, incompressible keystream; return sha256. */
function writeHuge(string $path, int $size, int $seed, bool $sparse): string
{
    $ctx = hash_init('sha256');
    $fp = fopen($path, 'wb');
    fillIncompressible($fp, $ctx, $size, $seed, $path);
    fclose($fp);

    return hash_final($ctx);
}

$start = microtime(true);

// --- small files ---
for ($n = 0; $n < $cfg['files']; $n++) {
    $r = rngFor($seed, "f$n");
    $dir = $dirs[$r % count($dirs)];
    $size = $buckets[($r >> 8) % count($buckets)];
    $rel = "$dir/file_" . $n . '.dat';
    $abs = "$root/$rel";
    $sha = writeSmall($abs, $size, $seed);
    fwrite($fh, json_encode(['p' => $rel, 's' => $size, 'sha256' => $sha]) . "\n");
    $totalBytes += $size;
    $count++;
    if ($count % 20000 === 0) {
        fwrite(STDERR, "  ..$count files\n");
    }
}

// --- huge-file tail ---
if (! $sparse) {
    $hi = 0;
    foreach ($cfg['big'] as $size => $howMany) {
        for ($k = 0; $k < $howMany; $k++) {
            $rel = 'uploads/2025/01/huge_' . $hi . '.bin';
            $abs = "$root/$rel";
            $sha = writeHuge($abs, (int) $size, $seed, $sparse);
            fwrite($fh, json_encode(['p' => $rel, 's' => (int) $size, 'sha256' => $sha]) . "\n");
            $totalBytes += (int) $size;
            $count++;
            $hi++;
            fwrite(STDERR, "  huge $rel (" . round($size / 1048576) . " MB)\n");
        }
    }
}

fclose($fh);
$elapsed = round(microtime(true) - $start, 1);
fprintf(
    STDOUT,
    "profile=%s files=%d bytes=%d (%.1f MB) seed=%d elapsed=%ss ground_truth=%s\n",
    $profile, $count, $totalBytes, $totalBytes / 1048576, $seed, $elapsed, $out
);
