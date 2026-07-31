<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Plugin\DownloadedChunk;
use App\Backup\V2\Plugin\PluginClient;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SAM_Backup_File_Chunker;
use SAM_Backup_File_Diff;

/**
 * A files-only PluginClient that drives the ACTUAL simplead-backup plugin diff + chunker classes
 * (SAM_Backup_File_Diff, SAM_Backup_File_Chunker — loaded from the plugin source, CLI-guarded so
 * they load under phpunit) against a controllable temp filesystem, producing REAL chunk zips that
 * the BackupRunner uploads to REAL MinIO.
 *
 * This lets the incremental/chain E2E mutate a fixture (change/add/delete files) between backups and
 * prove — through the same diff+chunk code production uses — that only changed+new files are
 * uploaded, unchanged files are skipped, tombstones are recorded, and the chain materialises
 * bit-identically. The DB is out of scope here (sessions run files-only); the full-DB-per-backup
 * behaviour is proven by the HTTP E2E against spike-wp.
 */
final class InProcessPluginClient implements PluginClient
{
    /** @var array<string, array{files_dir:string, chunks:list<array<string,mixed>>, tombstones:list<string>}> */
    private array $sessions = [];

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly string $root,
        private readonly int $threshold,
    ) {
        $base = rtrim((string) getenv('SAM_PLUGIN_SRC') ?: base_path('wordpress-plugin/simplead-backup'), '/');
        require_once $base.'/includes/files/class-file-chunker.php';
        require_once $base.'/includes/files/class-file-diff.php';

        if (! is_dir($this->root)) {
            @mkdir($this->root, 0777, true);
        }
    }

    // ── filesystem control (the "live site" the backup sees) ─────────────

    public function writeFile(string $rel, string $content): void
    {
        $abs = $this->root.'/'.ltrim($rel, '/');
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($abs, $content);
    }

    public function deleteFile(string $rel): void
    {
        $abs = $this->root.'/'.ltrim($rel, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Ground truth of the CURRENT live filesystem: rel-path => sha256.
     *
     * @return array<string, string>
     */
    public function liveState(): array
    {
        $out = [];
        foreach ($this->scan() as $f) {
            $out[$f['p']] = (string) hash_file('sha256', $this->root.'/'.$f['p']);
        }

        return $out;
    }

    // ── PluginClient ─────────────────────────────────────────────────────

    public function capabilities(): array
    {
        return [
            'plugin' => ['name' => 'simplead-backup', 'version' => '0.5.0'],
            'wordpress' => ['version' => '6.9'],
            'php' => ['version' => PHP_VERSION],
            'extensions' => ['zip' => true],
            'disk' => ['temp_dir' => '/tmp/sam_backup', 'temp_writable' => true, 'free_bytes' => 8 * 1024 ** 3],
            'database' => ['consistent_snapshot_supported' => true, 'server_version' => 'inproc', 'engine_family' => 'mysql', 'non_innodb_tables' => []],
        ];
    }

    public function filesInventory(string $sessionId, array $rules, array $options = []): array
    {
        $inventory = $this->scan();

        $chunker = new SAM_Backup_File_Chunker($this->root, $this->threshold, 'store');

        $base = $options['base_manifest'] ?? null;
        $incremental = is_array($base) && $base !== [];
        $tombstones = [];
        $diff = null;

        if ($incremental) {
            $differ = new SAM_Backup_File_Diff($this->root);
            $d = $differ->diff($inventory, $base);
            $tombstones = $d['tombstones'];
            $planFiles = array_merge($d['changed'], $d['new']);
            usort($planFiles, static fn (array $a, array $b): int => strcmp((string) $a['p'], (string) $b['p']));
            $plan = $chunker->plan($planFiles);
            $diff = [
                'mode' => 'incremental',
                'changed_count' => $d['changed_count'],
                'new_count' => $d['new_count'],
                'unchanged_count' => $d['unchanged_count'],
                'tombstone_count' => $d['tombstone_count'],
                'hash_confirmations' => $d['hash_confirmations'],
            ];
        } else {
            $plan = $chunker->plan($inventory);
        }

        $filesDir = sys_get_temp_dir().'/inproc_'.$sessionId.'_files';
        if (! is_dir($filesDir)) {
            @mkdir($filesDir, 0700, true);
        }

        $this->sessions[$sessionId] = [
            'files_dir' => $filesDir,
            'chunks' => $plan,
            'tombstones' => $tombstones,
        ];

        $totalBytes = 0;
        foreach ($inventory as $f) {
            $totalBytes += (int) $f['s'];
        }

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'exclusion_policy_hash' => 'inproc-excl',
            'mode' => $incremental ? 'incremental' : 'full',
            'total_files' => count($inventory),
            'total_bytes' => $totalBytes,
            'excluded_files' => 0,
            'excluded_bytes' => 0,
            'chunk_count' => count($plan),
            'compression' => 'store',
            'diff' => $diff,
            'tombstones' => $tombstones,
        ];
    }

    public function filesChunkExec(string $sessionId, int $chunkIndex): array
    {
        $s = $this->session($sessionId);
        $chunker = new SAM_Backup_File_Chunker($this->root, $this->threshold, 'store');
        $result = $chunker->exec_chunk($s['files_dir'], $chunkIndex, $s['chunks'][$chunkIndex]);

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'chunk_index' => $chunkIndex,
            'empty' => $result['empty'],
            'skipped' => $result['skipped'],
            'chunk_size' => $result['size'],
            'sha256' => $result['sha256'],
            'file_count' => $result['file_count'],
            'files' => $result['files'],
        ];
    }

    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        $s = $this->session($sessionId);
        $zip = $s['files_dir'].'/chunk_'.$chunkIndex.'.zip';
        if (! is_file($zip)) {
            throw new RuntimeException("chunk {$chunkIndex} zip not built (empty chunks must not be downloaded)");
        }

        // Copy to an independent temp file the runner owns (pull-and-free semantics).
        $tmp = (string) tempnam(sys_get_temp_dir(), 'inprocdl_');
        copy($zip, $tmp);
        $this->tempFiles[] = $tmp;

        $sha = (string) hash_file('sha256', $tmp);
        if ($deleteAfter) {
            @unlink($zip);
        }

        return new DownloadedChunk(
            path: $tmp,
            chunkIndex: $chunkIndex,
            size: (int) filesize($tmp),
            sha256: $sha,
            reportedSha256: $sha,
            httpStatus: 200,
            contentType: 'application/zip',
        );
    }

    public function dbDump(string $sessionId, array $params = []): array
    {
        throw new RuntimeException('InProcessPluginClient is files-only; dbDump must not be called.');
    }

    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        throw new RuntimeException('InProcessPluginClient is files-only; dbChunkDownload must not be called.');
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        foreach ($this->sessions as $s) {
            $this->rmrf($s['files_dir']);
        }
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @return array<int, array{p:string, s:int, m:int}> sorted by path
     */
    private function scan(): array
    {
        $files = [];
        $rootLen = strlen($this->root) + 1;
        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $files[] = [
                    'p' => substr((string) $file->getPathname(), $rootLen),
                    's' => (int) $file->getSize(),
                    'm' => (int) $file->getMTime(),
                ];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['p'], $b['p']));

        return $files;
    }

    /**
     * @return array{files_dir:string, chunks:list<array<string,mixed>>, tombstones:list<string>}
     */
    private function session(string $sessionId): array
    {
        if (! isset($this->sessions[$sessionId])) {
            throw new RuntimeException("unknown in-process session {$sessionId}");
        }

        return $this->sessions[$sessionId];
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
