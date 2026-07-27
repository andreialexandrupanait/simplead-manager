<?php

declare(strict_types=1);

/**
 * Lab-only CLI harness driving the PRODUCTION file-backup engine classes
 * (SAM_Backup_Exclusions / SAM_Backup_Inventory / SAM_Backup_File_Chunker) without WordPress
 * or HTTP — the same code the REST endpoints call. Lets files-test.sh validate exclusions,
 * inventory determinism and chunking (incl. the single-big-file case) end-to-end.
 *
 * Ops:
 *   --op=plan     --root=DIR [--rules=RULES.json] [--include-defaults=1] [--threshold=N]
 *                 [--compression=store|deflate] --session=SESSION_DIR
 *                 → build inventory + chunk plan, persist SESSION_DIR/files/plan.json, print summary.
 *   --op=preview  (same inputs) → counts + estimate + hash only, no session, no reads.
 *   --op=exec     --session=SESSION_DIR --chunk=N → materialise ONE chunk, print result JSON.
 *
 * Prints one JSON object per invocation on stdout.
 */

require __DIR__ . '/../includes/files/class-exclusions.php';
require __DIR__ . '/../includes/files/class-inventory.php';
require __DIR__ . '/../includes/files/class-file-chunker.php';

$opts = getopt('', array(
    'op:', 'root::', 'rules::', 'include-defaults::', 'threshold::', 'compression::',
    'session::', 'chunk::',
));

$op = $opts['op'] ?? '';

function out($data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

function load_rules(?string $path): array {
    if ($path === null || $path === '' || !is_file($path)) {
        return array();
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : array();
}

if ($op === 'plan' || $op === 'preview') {
    $root = $opts['root'] ?? '';
    if ($root === '' || !is_dir($root)) {
        fwrite(STDERR, "--root must be an existing directory\n");
        exit(2);
    }
    $rules       = load_rules($opts['rules'] ?? null);
    $with_def    = !isset($opts['include-defaults']) || $opts['include-defaults'] !== '0';
    $threshold   = (int) ($opts['threshold'] ?? SAM_Backup_File_Chunker::DEFAULT_THRESHOLD);
    $compression = (string) ($opts['compression'] ?? 'store');

    $exclusions = SAM_Backup_Exclusions::from_rules($rules, $with_def);
    $inventory  = new SAM_Backup_Inventory($root, $exclusions);

    if ($op === 'preview') {
        $p = $inventory->preview();
        $p['rule_count'] = $exclusions->rule_count();
        out($p);
        exit(0);
    }

    $inv     = $inventory->build();
    $chunker = new SAM_Backup_File_Chunker($root, $threshold, $compression);
    $plan    = $chunker->plan($inv['files']);

    $session = $opts['session'] ?? '';
    if ($session === '') {
        fwrite(STDERR, "--session required for op=plan\n");
        exit(2);
    }
    $files_dir = rtrim($session, '/') . '/files';
    if (!is_dir($files_dir) && !mkdir($files_dir, 0700, true) && !is_dir($files_dir)) {
        fwrite(STDERR, "cannot create $files_dir\n");
        exit(1);
    }

    $plan_doc = array(
        'root'                  => $inv['root'],
        'threshold'             => $threshold,
        'compression'           => $chunker->compression(),
        'exclusion_policy_hash' => $inv['exclusion_policy_hash'],
        'total_files'           => $inv['total_files'],
        'total_bytes'           => $inv['total_bytes'],
        'excluded_files'        => $inv['excluded_files'],
        'excluded_bytes'        => $inv['excluded_bytes'],
        'chunk_count'           => count($plan),
        'chunks'                => $plan,
    );
    file_put_contents($files_dir . '/plan.json', json_encode($plan_doc, JSON_UNESCAPED_SLASHES));

    $summary = array();
    $largest = 0;
    foreach ($plan as $c) {
        $summary[] = array('index' => $c['index'], 'file_count' => $c['file_count'], 'size' => $c['size'], 'oversize' => $c['oversize']);
        if ($c['size'] > $largest) {
            $largest = $c['size'];
        }
    }
    out(array(
        'root'                  => $inv['root'],
        'threshold'             => $threshold,
        'compression'           => $chunker->compression(),
        'exclusion_policy_hash' => $inv['exclusion_policy_hash'],
        'rule_count'            => $exclusions->rule_count(),
        'total_files'           => $inv['total_files'],
        'total_bytes'           => $inv['total_bytes'],
        'excluded_files'        => $inv['excluded_files'],
        'excluded_bytes'        => $inv['excluded_bytes'],
        'chunk_count'           => count($plan),
        'largest_chunk'         => $largest,
        'chunks'                => $summary,
    ));
    exit(0);
}

if ($op === 'exec') {
    $session = $opts['session'] ?? '';
    $chunk   = isset($opts['chunk']) ? (int) $opts['chunk'] : -1;
    if ($session === '' || $chunk < 0) {
        fwrite(STDERR, "--session and --chunk required for op=exec\n");
        exit(2);
    }
    $files_dir = rtrim($session, '/') . '/files';
    $plan_file = $files_dir . '/plan.json';
    if (!is_file($plan_file)) {
        fwrite(STDERR, "plan.json not found in $files_dir\n");
        exit(1);
    }
    $doc = json_decode((string) file_get_contents($plan_file), true);
    if (!is_array($doc) || $chunk >= $doc['chunk_count']) {
        fwrite(STDERR, "invalid chunk index\n");
        exit(1);
    }

    $chunker = new SAM_Backup_File_Chunker($doc['root'], (int) $doc['threshold'], (string) $doc['compression']);
    try {
        $result = $chunker->exec_chunk($files_dir, $chunk, $doc['chunks'][$chunk]);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'EXEC ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
    out($result);
    exit(0);
}

fwrite(STDERR, "unknown --op (expected plan|preview|exec)\n");
exit(2);
