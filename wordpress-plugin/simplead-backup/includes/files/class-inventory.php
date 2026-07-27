<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_Inventory
 * ====================
 * Walks a root directory (ABSPATH in production — which naturally covers Multisite and every
 * wp-content subtree) with a RecursiveIteratorIterator, applies the exclusion policy BEFORE a
 * file is admitted, and produces a deterministic inventory:
 *
 *   { p: relative-path, s: size-bytes, m: mtime } … sorted by path
 *
 * Determinism: RecursiveDirectoryIterator order is filesystem-dependent, so the result is
 * sorted by relative path before returning — two runs over an unchanged tree yield an identical
 * list, an identical total, and (given the same rules) an identical exclusion_policy_hash.
 *
 * Excluded files are NEVER read or hashed here — exclusion is decided from the walk's stat
 * (size + mtime) alone, so excluded content costs one lstat and nothing more.
 *
 * Path-traversal guard: every entry's realpath must resolve inside the walk root; symlinks
 * that escape the root are dropped. Nothing outside the root is ever emitted.
 */
final class SAM_Backup_Inventory {

    private string $root;
    private SAM_Backup_Exclusions $exclusions;

    public function __construct(string $root, SAM_Backup_Exclusions $exclusions) {
        $real = realpath($root);
        $this->root       = $real !== false ? rtrim($real, '/') : rtrim($root, '/');
        $this->exclusions = $exclusions;
    }

    public function root(): string {
        return $this->root;
    }

    /**
     * Full inventory: builds and returns the sorted file list.
     *
     * @return array{
     *   root:string, total_files:int, total_bytes:int, excluded_files:int, excluded_bytes:int,
     *   exclusion_policy_hash:string, files:array<int,array{p:string,s:int,m:int}>
     * }
     */
    public function build(): array {
        return $this->run(true);
    }

    /**
     * Preview: counts + estimated bytes + excluded counts, WITHOUT materialising the list.
     * Cheap for the UI ("this backup will include N files ≈ X, exclude M files").
     *
     * @return array{
     *   root:string, total_files:int, total_bytes:int, excluded_files:int, excluded_bytes:int,
     *   exclusion_policy_hash:string
     * }
     */
    public function preview(): array {
        $r = $this->run(false);
        unset($r['files']);
        return $r;
    }

    /**
     * @return array<string,mixed>
     */
    private function run(bool $collect): array {
        $now            = time();
        $files          = array();
        $total_files    = 0;
        $total_bytes    = 0;
        $excluded_files = 0;
        $excluded_bytes = 0;
        $root_len       = strlen($this->root) + 1;

        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $real = $file->getRealPath();
                if ($real === false || !$this->is_within_root($real)) {
                    continue; // traversal / escaping symlink
                }
                $rel  = substr($real, $root_len);
                $size = (int) $file->getSize();
                $mtime = (int) $file->getMTime();

                // Exclusion decided from stat only — excluded files are never read/hashed.
                if ($this->exclusions->is_excluded($rel, $size, $mtime, $now)) {
                    $excluded_files++;
                    $excluded_bytes += $size;
                    continue;
                }

                $total_files++;
                $total_bytes += $size;
                if ($collect) {
                    $files[] = array('p' => $rel, 's' => $size, 'm' => $mtime);
                }
            }
        }

        if ($collect) {
            usort($files, static function (array $a, array $b): int {
                return strcmp($a['p'], $b['p']);
            });
        }

        $out = array(
            'root'                  => $this->root,
            'total_files'           => $total_files,
            'total_bytes'           => $total_bytes,
            'excluded_files'        => $excluded_files,
            'excluded_bytes'        => $excluded_bytes,
            'exclusion_policy_hash' => $this->exclusions->policy_hash(),
        );
        if ($collect) {
            $out['files'] = $files;
        }
        return $out;
    }

    private function is_within_root(string $real): bool {
        return $real === $this->root || strpos($real, $this->root . '/') === 0;
    }
}
