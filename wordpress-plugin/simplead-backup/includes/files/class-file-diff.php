<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_File_Diff
 * ====================
 * Turns a CURRENT inventory (already exclusion-filtered, sorted by path — the {p,s,m} list from
 * SAM_Backup_Inventory) plus a BASE manifest (the {p,sha256,s,m?} list carried by the manifest of
 * the full/previous backup that this incremental is layered on) into an incremental plan:
 *
 *   changed    — path exists in base, content differs (sha256 differs) → re-uploaded.
 *   new        — path absent from base → uploaded.
 *   unchanged  — same content → NEVER re-uploaded (only counted).
 *   tombstones — path present in base, absent now (deleted) → recorded so restore removes it.
 *
 * Only changed + new feed the chunker (same STORE / big-file-solo-chunk / empty-chunk rules as a
 * full). The DB is intentionally NOT diffed here — the architecture dumps the full DB every backup
 * (docs/backup/TARGET-ARCHITECTURE.md §DB strategy); this class is FILES only.
 *
 * Change detection (cheap-first, then authoritative):
 *   1. Fast unchanged: base carries mtime AND (size,mtime) are byte-identical → unchanged WITHOUT
 *      hashing (one lstat already done by the walk). This is why the manifest records mtime.
 *   2. Otherwise (size differs, mtime differs, or the base predates mtime-in-manifest) the current
 *      file is hashed with hash_file() and compared to the base sha256 — the authoritative check.
 *      Equal → unchanged (a touch that did not change content re-uploads NOTHING). Differ → changed.
 *
 * So a false "maybe changed" from the mtime/size heuristic is always resolved by an exact sha256
 * compare before a file is admitted to the changed set — the incremental never carries an unchanged
 * file, and never drops a genuinely-changed one.
 *
 * Path-traversal guard: a current path is only hashed if it still resolves to a real file inside the
 * walk root (mirrors SAM_Backup_File_Chunker::exec_chunk).
 */
final class SAM_Backup_File_Diff {

    private string $root;

    public function __construct(string $root) {
        $real = realpath($root);
        $this->root = $real !== false ? rtrim($real, '/') : rtrim($root, '/');
    }

    public function root(): string {
        return $this->root;
    }

    /**
     * Diff the current inventory against a base manifest file list.
     *
     * @param array<int,array{p:string,s:int,m?:int}>            $inventory current files (sorted by path)
     * @param array<int,array{p:string,sha256?:string,s?:int,m?:int}> $base   base manifest file list
     * @return array{
     *   changed:array<int,array{p:string,s:int,m:int}>,
     *   new:array<int,array{p:string,s:int,m:int}>,
     *   tombstones:array<int,string>,
     *   changed_count:int, new_count:int, unchanged_count:int, tombstone_count:int,
     *   hash_confirmations:int
     * }
     */
    public function diff(array $inventory, array $base): array {
        $base_by_path = array();
        foreach ($base as $b) {
            if (isset($b['p'])) {
                $base_by_path[(string) $b['p']] = $b;
            }
        }

        $changed = array();
        $new     = array();
        $unchanged_count   = 0;
        $hash_confirmations = 0;
        $current_paths = array();

        foreach ($inventory as $f) {
            $p = (string) $f['p'];
            $s = (int) $f['s'];
            $m = (int) ($f['m'] ?? 0);
            $current_paths[$p] = true;

            if (!isset($base_by_path[$p])) {
                $new[] = array('p' => $p, 's' => $s, 'm' => $m);
                continue;
            }

            $b     = $base_by_path[$p];
            $b_size = isset($b['s']) ? (int) $b['s'] : null;
            $b_mtime = isset($b['m']) ? (int) $b['m'] : null;
            $b_sha  = isset($b['sha256']) ? (string) $b['sha256'] : '';

            // 1. Fast unchanged: size AND mtime identical (base must carry mtime).
            if ($b_mtime !== null && $b_size !== null && $s === $b_size && $m === $b_mtime) {
                $unchanged_count++;
                continue;
            }

            // 2. Authoritative sha256 compare. A differing size still gets confirmed by hash so the
            //    manifest's sha256 is the single source of truth for "changed".
            $cur_sha = $this->hash($p);
            if ($cur_sha !== null && $b_sha !== '' && hash_equals($b_sha, $cur_sha)) {
                $hash_confirmations++;
                $unchanged_count++;
                continue;
            }

            $changed[] = array('p' => $p, 's' => $s, 'm' => $m);
        }

        // Tombstones: every base path no longer present in the current inventory.
        $tombstones = array();
        foreach ($base_by_path as $p => $_b) {
            if (!isset($current_paths[$p])) {
                $tombstones[] = (string) $p;
            }
        }
        sort($tombstones, SORT_STRING);

        return array(
            'changed'            => $changed,
            'new'                => $new,
            'tombstones'         => $tombstones,
            'changed_count'      => count($changed),
            'new_count'          => count($new),
            'unchanged_count'    => $unchanged_count,
            'tombstone_count'    => count($tombstones),
            'hash_confirmations' => $hash_confirmations,
        );
    }

    /**
     * sha256 of a current file, or null if it vanished / escaped the root since the walk.
     */
    private function hash(string $rel): ?string {
        $abs  = $this->root . '/' . $rel;
        $real = realpath($abs);
        if ($real === false || !is_file($real) || strpos($real, $this->root . '/') !== 0) {
            return null;
        }
        $sha = hash_file('sha256', $real);
        return $sha === false ? null : $sha;
    }
}
