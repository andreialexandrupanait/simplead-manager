<?php

declare(strict_types=1);

/**
 * Lab-only restore-oracle for the file-backup engine. Extracts every pulled chunk zip into a
 * tree and re-checks each file's sha256 against the ground-truth manifest — the same integrity
 * bar the production restore must meet (0 missing / 0 mismatch).
 *
 *   php files-oracle.php --zips=DIR --ground=GT.jsonl --extract=DIR [--forbidden=a,b,c]
 *
 * GT.jsonl: one JSON object per line, {p: rel-path, s: size, sha256: hex}.
 * --forbidden: comma-separated rel-paths that MUST NOT exist after extraction (excluded files).
 *
 * Prints a JSON verdict; exit 0 only if missing==0 && mismatch==0 && no forbidden path present.
 */

$opts = getopt('', array('zips:', 'ground:', 'extract:', 'forbidden::'));

$zips_dir   = $opts['zips'] ?? '';
$ground      = $opts['ground'] ?? '';
$extract_dir = $opts['extract'] ?? '';
$forbidden   = array_filter(explode(',', (string) ($opts['forbidden'] ?? '')));

if ($zips_dir === '' || $ground === '' || $extract_dir === '') {
    fwrite(STDERR, "--zips, --ground and --extract are required\n");
    exit(2);
}
if (!is_dir($extract_dir) && !mkdir($extract_dir, 0700, true) && !is_dir($extract_dir)) {
    fwrite(STDERR, "cannot create extract dir\n");
    exit(1);
}

// --- extract every chunk zip (numeric order) ---
$zips = glob(rtrim($zips_dir, '/') . '/chunk_*.zip');
natsort($zips);
$extracted_ok = 0;
foreach ($zips as $z) {
    $zip = new ZipArchive();
    if ($zip->open($z) !== true) {
        fwrite(STDERR, "cannot open $z\n");
        exit(1);
    }
    if (!$zip->extractTo($extract_dir)) {
        fwrite(STDERR, "extract failed for $z\n");
        exit(1);
    }
    $zip->close();
    $extracted_ok++;
}

// --- verify each ground-truth file ---
$total = 0;
$ok = 0;
$missing = array();
$mismatch = array();

$fh = fopen($ground, 'rb');
if ($fh === false) {
    fwrite(STDERR, "cannot open ground truth\n");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $e = json_decode($line, true);
    if (!is_array($e) || !isset($e['p'], $e['sha256'])) {
        continue;
    }
    $total++;
    $path = rtrim($extract_dir, '/') . '/' . $e['p'];
    if (!is_file($path)) {
        $missing[] = $e['p'];
        continue;
    }
    $sha = hash_file('sha256', $path);
    if ($sha !== $e['sha256']) {
        $mismatch[] = $e['p'];
        continue;
    }
    $ok++;
}
fclose($fh);

// --- forbidden (excluded) paths must be absent ---
$leaked = array();
foreach ($forbidden as $rel) {
    $rel = trim($rel);
    if ($rel === '') {
        continue;
    }
    if (file_exists(rtrim($extract_dir, '/') . '/' . $rel)) {
        $leaked[] = $rel;
    }
}

$pass = (count($missing) === 0 && count($mismatch) === 0 && count($leaked) === 0);

echo json_encode(array(
    'zips_extracted' => $extracted_ok,
    'ground_total'   => $total,
    'ok'             => $ok,
    'missing'        => count($missing),
    'mismatch'       => count($mismatch),
    'missing_list'   => array_slice($missing, 0, 10),
    'mismatch_list'  => array_slice($mismatch, 0, 10),
    'forbidden_leaked' => $leaked,
    'pass'           => $pass,
), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

exit($pass ? 0 : 1);
