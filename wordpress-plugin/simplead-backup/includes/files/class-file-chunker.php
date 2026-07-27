<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_File_Chunker
 * =======================
 * Turns an inventory (already exclusion-filtered, sorted by path) into a deterministic PLAN of
 * file chunks, and materialises one chunk at a time as `files/chunk_N.zip` in the session temp.
 *
 * Grouping (deterministic, directory-local):
 *   The inventory is sorted by path, so files in the same directory are adjacent. A greedy
 *   bin-pack fills a chunk up to `threshold` bytes (default 100 MiB), preserving that locality.
 *
 * THE KEY RULE (validated in docs/backup/spike/SINGLE-BIGFILE-RESULTS.md):
 *   A single file LARGER than the threshold cannot be grouped, so it becomes ONE chunk by
 *   itself (never skipped, never split intra-file in v1). Consequence, documented:
 *     temp peak = max(largest single file, threshold)
 *   — because chunks are pulled-and-freed one at a time, temp is bounded by the biggest chunk,
 *   NOT by the total backup size. A site holding an N-GB single file needs ≈ N GB free temp
 *   during that one chunk. Intra-file streaming multipart is a later, optional hardening.
 *
 * Empty-chunk contract: a chunk that ends up with 0 files is NEVER written (no empty zip, no
 * manifest entry). The plan never emits an empty chunk; exec also returns empty=true / size=0
 * if every file in a chunk vanished between plan and exec, so the orchestrator skips it.
 *
 * ZipArchive streams at close() — addFile() records a reference and the bytes are read only
 * when close() runs, so RAM stays flat even for a multi-GB file (spike: anon RSS ~137 MB across
 * a 2 GB zip). Per-file sha256 is computed with hash_file() (also streaming) so the manifest
 * carries a restore-oracle-grade checksum per file without buffering.
 *
 * Compression: STORE by default. WP payload bytes are dominated by already-compressed media
 * (jpg/png/mp4/pdf/woff) — DEFLATE burns CPU for ~0 gain and, on truly incompressible data,
 * makes the archive slightly LARGER. See the benchmark in tests/files-test.sh / DECISION-LOG
 * D-004. The manager may override to `deflate` per-site for text-heavy trees.
 */
final class SAM_Backup_File_Chunker {

    const DEFAULT_THRESHOLD = 104857600; // 100 MiB

    private string $root;
    private int $threshold;
    private string $compression; // 'store' | 'deflate'

    public function __construct(string $root, int $threshold = self::DEFAULT_THRESHOLD, string $compression = 'store') {
        $real = realpath($root);
        $this->root        = $real !== false ? rtrim($real, '/') : rtrim($root, '/');
        $this->threshold   = max(1, $threshold);
        $this->compression = strtolower($compression) === 'deflate' ? 'deflate' : 'store';
    }

    public function compression(): string {
        return $this->compression;
    }

    public function threshold(): int {
        return $this->threshold;
    }

    /**
     * Build the chunk plan from an inventory file list ([{p,s,m}, …], sorted by path).
     * Deterministic. Never emits an empty chunk.
     *
     * @param array<int,array{p:string,s:int,m?:int}> $files
     * @return array<int,array{index:int,file_count:int,size:int,oversize:bool,files:array<int,array{p:string,s:int}>}>
     */
    public function plan(array $files): array {
        $chunks  = array();
        $current = array();
        $cur_sz  = 0;

        $flush = static function () use (&$chunks, &$current, &$cur_sz): void {
            if (!empty($current)) {
                $chunks[] = array('files' => $current, 'size' => $cur_sz, 'oversize' => false);
                $current  = array();
                $cur_sz   = 0;
            }
        };

        foreach ($files as $f) {
            $size = (int) $f['s'];
            $entry = array('p' => $f['p'], 's' => $size);

            if ($size > $this->threshold) {
                // Larger than a whole chunk → its own solo chunk (the spike rule).
                $flush();
                $chunks[] = array('files' => array($entry), 'size' => $size, 'oversize' => true);
                continue;
            }
            if ($cur_sz + $size > $this->threshold && !empty($current)) {
                $flush();
            }
            $current[] = $entry;
            $cur_sz   += $size;
        }
        $flush();

        $out = array();
        foreach ($chunks as $i => $c) {
            $out[] = array(
                'index'      => $i,
                'file_count' => count($c['files']),
                'size'       => $c['size'],
                'oversize'   => $c['oversize'],
                'files'      => $c['files'],
            );
        }
        return $out;
    }

    /**
     * Materialise ONE chunk into $session_files_dir/chunk_{index}.zip and its per-file manifest
     * fragment chunk_{index}.manifest.json.
     *
     * Idempotent: if the zip + `.done` marker already exist, returns the recorded result without
     * rebuilding (safe retry). Empty-chunk contract honoured (size 0 / empty=true, no zip kept).
     *
     * @param array{files:array<int,array{p:string,s:int}>} $chunk one plan entry
     * @return array{
     *   index:int, empty:bool, zip:?string, size:int, sha256:?string,
     *   file_count:int, files:array<int,array{p:string,s:int,sha256:string}>, skipped:bool
     * }
     */
    public function exec_chunk(string $session_files_dir, int $index, array $chunk): array {
        if (!is_dir($session_files_dir) && !@mkdir($session_files_dir, 0700, true) && !is_dir($session_files_dir)) {
            throw new RuntimeException('Cannot create files chunk dir: ' . $session_files_dir);
        }
        $zip_file      = $session_files_dir . '/chunk_' . $index . '.zip';
        $manifest_file = $session_files_dir . '/chunk_' . $index . '.manifest.json';
        $done_marker   = $session_files_dir . '/chunk_' . $index . '.done';

        // Idempotent skip: completed AND artefacts still present.
        if (file_exists($done_marker) && file_exists($manifest_file)) {
            $prev = json_decode((string) file_get_contents($manifest_file), true);
            if (is_array($prev)) {
                $empty = !empty($prev['empty']);
                if ($empty || file_exists($zip_file)) {
                    $prev['skipped'] = true;
                    return $prev;
                }
            }
            @unlink($done_marker); // artefacts gone — rebuild
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot open zip for chunk {$index}.");
        }

        $entries  = array();
        $added    = 0;
        $store    = ($this->compression === 'store');
        $root_pfx = $this->root . '/';

        foreach ($chunk['files'] as $f) {
            $rel = $f['p'];
            $abs = $root_pfx . $rel;
            $real = realpath($abs);
            // Guard: still a real file inside the root (may have vanished/changed since plan).
            if ($real === false || !is_file($real) || strpos($real, $root_pfx) !== 0) {
                continue;
            }
            if (!$zip->addFile($real, $rel)) {
                throw new RuntimeException("addFile failed for {$rel} in chunk {$index}.");
            }
            // Per-file compression method (STORE avoids CPU on incompressible payloads).
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($rel, $store ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
            }
            // Streaming per-file checksum for the restore-oracle-grade manifest.
            $sha = hash_file('sha256', $real);
            $entries[] = array('p' => $rel, 's' => (int) filesize($real), 'sha256' => $sha);
            $added++;
        }

        if ($added === 0) {
            // Empty-chunk contract: close, drop the (empty) zip, record empty result.
            $zip->close();
            @unlink($zip_file);
            $result = array(
                'index' => $index, 'empty' => true, 'zip' => null, 'size' => 0, 'sha256' => null,
                'file_count' => 0, 'files' => array(), 'skipped' => false,
            );
            file_put_contents($manifest_file, wp_json_encode_maybe($result));
            file_put_contents($done_marker, '0');
            return $result;
        }

        if (!$zip->close()) {
            throw new RuntimeException("ZipArchive::close() failed for chunk {$index}.");
        }

        clearstatcache(true, $zip_file);
        $size = file_exists($zip_file) ? (int) filesize($zip_file) : 0;
        $sha  = $size > 0 ? hash_file('sha256', $zip_file) : null;

        // Manifest fragment contributes {path_rel,size,sha256,chunk_index} to the global manifest.
        foreach ($entries as &$e) {
            $e['chunk_index'] = $index;
        }
        unset($e);

        $result = array(
            'index'      => $index,
            'empty'      => false,
            'zip'        => $zip_file,
            'size'       => $size,
            'sha256'     => $sha,
            'file_count' => $added,
            'files'      => $entries,
            'skipped'    => false,
        );
        file_put_contents($manifest_file, wp_json_encode_maybe($result));
        file_put_contents($done_marker, (string) $size);
        return $result;
    }
}

/**
 * json_encode with WP's helper when available (adds no BOM, respects WP filters), else plain.
 */
if (!function_exists('wp_json_encode_maybe')) {
    function wp_json_encode_maybe($data): string {
        if (function_exists('wp_json_encode')) {
            $s = wp_json_encode($data);
            if ($s !== false) {
                return $s;
            }
        }
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
