<?php

declare(strict_types=1);

/**
 * Lab-only, WP-free CLI proof for the incremental FILE diff (SAM_Backup_File_Diff).
 *
 * Builds a deterministic fixture, captures a BASE manifest (as a full backup would record it:
 * {p,sha256,s,m}), then mutates the tree — change some files (content AND size), add some, delete
 * some, and touch one without changing content — and asserts the diff:
 *
 *   changed   == exactly the content-changed files
 *   new       == exactly the added files
 *   tombstones== exactly the deleted files
 *   unchanged == everything else (including the no-op touch, resolved by sha256, NOT re-uploaded)
 *
 * Prints a JSON verdict; exit 0 only if every set matches. Run:
 *   php wordpress-plugin/simplead-backup/tests/files-diff-harness.php
 */

require_once __DIR__ . '/../includes/files/class-file-diff.php';

function scan_inventory(string $root): array {
    $files = [];
    $len = strlen($root) + 1;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = ['p' => substr($f->getPathname(), $len), 's' => (int) $f->getSize(), 'm' => (int) $f->getMTime()];
        }
    }
    usort($files, static fn ($a, $b) => strcmp($a['p'], $b['p']));
    return $files;
}

$root = sys_get_temp_dir() . '/sam_diff_harness_' . uniqid('', true);
@mkdir($root . '/a', 0777, true);
@mkdir($root . '/b', 0777, true);

$initial = [
    'a/keep.txt'  => 'keep-me',
    'a/mod.txt'   => 'original-content',
    'a/touch.txt' => 'touched-but-same',
    'b/gone.txt'  => 'will-be-deleted',
    'b/stay.bin'  => str_repeat('x', 500),
];
foreach ($initial as $rel => $content) {
    file_put_contents($root . '/' . $rel, $content);
}

// Base manifest as a full backup records it (with sha256 + mtime).
$base = [];
foreach (array_keys($initial) as $rel) {
    $abs = $root . '/' . $rel;
    $base[] = ['p' => $rel, 'sha256' => hash_file('sha256', $abs), 's' => filesize($abs), 'm' => filemtime($abs)];
}

// Mutate. sleep(1) so a genuine change also moves mtime (belt-and-suspenders; size already differs).
sleep(1);
file_put_contents($root . '/a/mod.txt', 'CHANGED-CONTENT-AND-LONGER'); // changed
file_put_contents($root . '/a/new.txt', 'brand-new-file');            // new
file_put_contents($root . '/b/new2.bin', str_repeat('y', 300));       // new
unlink($root . '/b/gone.txt');                                        // deleted
touch($root . '/a/touch.txt', time() + 5);                            // mtime moved, content identical

$diff = (new SAM_Backup_File_Diff($root))->diff(scan_inventory($root), $base);

$changed = array_column($diff['changed'], 'p');
$new     = array_column($diff['new'], 'p');
$tombs   = $diff['tombstones'];
sort($changed); sort($new); sort($tombs);

$expect = [
    'changed'    => ['a/mod.txt'],
    'new'        => ['a/new.txt', 'b/new2.bin'],
    'tombstones' => ['b/gone.txt'],
];

$ok = $changed === $expect['changed']
    && $new === $expect['new']
    && $tombs === $expect['tombstones']
    // keep.txt, stay.bin, touch.txt (touched-but-identical) → unchanged. touch.txt is resolved by
    // sha256 (mtime moved), proving a no-op touch re-uploads NOTHING.
    && $diff['unchanged_count'] === 3
    && $diff['hash_confirmations'] >= 1;

// Cleanup.
$rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($rit as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

fwrite(STDOUT, json_encode([
    'ok'                 => $ok,
    'changed'            => $changed,
    'new'                => $new,
    'tombstones'         => $tombs,
    'unchanged_count'    => $diff['unchanged_count'],
    'hash_confirmations' => $diff['hash_confirmations'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

exit($ok ? 0 : 1);
