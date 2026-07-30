<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SAM_Backup_Restore_Engine;
use Tests\TestCase;
use ZipArchive;

/**
 * In-process proof of the plugin's staged, atomic FILE restore engine
 * (SAM_Backup_Restore_Engine, loaded from the plugin source, CLI-guarded so it loads under phpunit)
 * against a controllable temp "live site". No WP, no HTTP, no MinIO — pure filesystem — so the
 * journaled swap, SAFE_MERGE vs MIRROR semantics, selective scope, and mid-swap rollback are proven
 * deterministically. The DB half needs mysqli (absent in lab-php); it is proven over HTTP against
 * spike-wp + a verify script.
 */
#[Group('restore')]
class RestoreEngineTest extends TestCase
{
    private string $abspath;

    private string $workDir;

    private string $chunkDir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = base_path('wordpress-plugin/simplead-backup');
        require_once $base.'/includes/restore/class-restore-engine.php';

        $root = sys_get_temp_dir().'/sam_restore_engine_'.uniqid('', true);
        $this->abspath = $root.'/site';
        $this->workDir = $root.'/work';
        $this->chunkDir = $root.'/chunks';
        @mkdir($this->abspath, 0755, true);
        @mkdir($this->workDir, 0755, true);
        @mkdir($this->chunkDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->abspath));
        parent::tearDown();
    }

    private const DIR = 'wp-content/uploads/lab';

    // ── Test 1: full round-trip — SAFE_MERGE keeps live-only, MIRROR deletes it ──

    public function test_safe_merge_restores_files_and_keeps_live_only_additions(): void
    {
        // Backup content (the "known good" state).
        $backup = [
            self::DIR.'/a.txt' => 'ORIGINAL-A',
            self::DIR.'/b.txt' => 'ORIGINAL-B',
            self::DIR.'/nested/c.txt' => 'ORIGINAL-C',
        ];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        // Mutate the live site AFTER the backup: change a, delete b, add d (not in backup).
        $this->writeLiveFile(self::DIR.'/a.txt', 'MODIFIED-A');
        @unlink($this->abspath.'/'.self::DIR.'/b.txt');
        $this->writeLiveFile(self::DIR.'/d.txt', 'ADDED-AFTER-BACKUP');

        $engine = $this->engine('safemerge');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        // Backup files restored exactly.
        $this->assertLive(self::DIR.'/a.txt', 'ORIGINAL-A');
        $this->assertLive(self::DIR.'/b.txt', 'ORIGINAL-B');    // recreated
        $this->assertLive(self::DIR.'/nested/c.txt', 'ORIGINAL-C');
        // SAFE_MERGE: the file added after the backup SURVIVES.
        $this->assertLive(self::DIR.'/d.txt', 'ADDED-AFTER-BACKUP');

        $engine->commit();
        $this->assertDirDoesNotExist($this->abspath.'/'.SAM_Backup_Restore_Engine::TRASH_PREFIX.$engine->token());
    }

    public function test_mirror_restores_files_and_deletes_live_only_additions(): void
    {
        $backup = [
            self::DIR.'/a.txt' => 'ORIGINAL-A',
            self::DIR.'/b.txt' => 'ORIGINAL-B',
            self::DIR.'/nested/c.txt' => 'ORIGINAL-C',
        ];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $this->writeLiveFile(self::DIR.'/a.txt', 'MODIFIED-A');
        @unlink($this->abspath.'/'.self::DIR.'/b.txt');
        $this->writeLiveFile(self::DIR.'/d.txt', 'ADDED-AFTER-BACKUP');
        $this->writeLiveFile(self::DIR.'/nested/e.txt', 'ADDED-NESTED');

        $engine = $this->engine('mirror');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        $this->assertLive(self::DIR.'/a.txt', 'ORIGINAL-A');
        $this->assertLive(self::DIR.'/b.txt', 'ORIGINAL-B');
        $this->assertLive(self::DIR.'/nested/c.txt', 'ORIGINAL-C');
        // MIRROR: files absent from the backup are deleted → EXACT reproduction.
        $this->assertFileDoesNotExist($this->abspath.'/'.self::DIR.'/d.txt');
        $this->assertFileDoesNotExist($this->abspath.'/'.self::DIR.'/nested/e.txt');

        // The mirrored live tree equals the backup set exactly.
        $this->assertEqualsCanonicalizing(array_keys($backup), $this->liveTree(self::DIR));

        $engine->commit();
    }

    // ── Test 2: selective (folder scope) — only the scoped folder is touched ──

    public function test_selective_folder_scope_only_touches_that_folder(): void
    {
        $dir1 = 'wp-content/uploads/one';
        $dir2 = 'wp-content/uploads/two';
        $backup = [
            $dir1.'/x.txt' => 'ONE-X',
            $dir2.'/y.txt' => 'TWO-Y',
        ];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        // Mutate BOTH folders.
        $this->writeLiveFile($dir1.'/x.txt', 'ONE-X-CHANGED');
        $this->writeLiveFile($dir1.'/extra1.txt', 'ONE-EXTRA');
        $this->writeLiveFile($dir2.'/y.txt', 'TWO-Y-CHANGED');
        $this->writeLiveFile($dir2.'/extra2.txt', 'TWO-EXTRA');

        // Restore MIRROR but scoped to dir1 only.
        $engine = $this->engine('sel');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [$dir1],
            'keep_paths' => [$dir1.'/x.txt'], // only dir1's file is in scope
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        // dir1: restored + mirrored (extra deleted).
        $this->assertLive($dir1.'/x.txt', 'ONE-X');
        $this->assertFileDoesNotExist($this->abspath.'/'.$dir1.'/extra1.txt');
        // dir2: completely untouched (out of scope).
        $this->assertLive($dir2.'/y.txt', 'TWO-Y-CHANGED');
        $this->assertLive($dir2.'/extra2.txt', 'TWO-EXTRA');

        $engine->commit();
    }

    // ── Test 3: kill mid-swap → journaled rollback leaves the site intact ──

    public function test_crash_mid_swap_rolls_back_to_pre_apply_state(): void
    {
        $backup = [
            self::DIR.'/a.txt' => 'ORIGINAL-A',
            self::DIR.'/b.txt' => 'ORIGINAL-B',
            self::DIR.'/c.txt' => 'ORIGINAL-C',
            self::DIR.'/d.txt' => 'ORIGINAL-D',
        ];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        // Pre-apply (modified) state — this is exactly what must survive a crash.
        $this->writeLiveFile(self::DIR.'/a.txt', 'MODIFIED-A');
        $this->writeLiveFile(self::DIR.'/b.txt', 'MODIFIED-B');
        @unlink($this->abspath.'/'.self::DIR.'/c.txt');
        $this->writeLiveFile(self::DIR.'/live-only.txt', 'LIVE-ONLY');
        $preApply = $this->snapshot(self::DIR);

        $engine = $this->engine('crash');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));

        // Inject a crash AFTER the 2nd swap step (mid-way through the swap).
        $step = 0;
        $engine->set_fault(function (string $phase) use (&$step): void {
            if ($phase === 'files_swap') {
                $step++;
                if ($step === 2) {
                    throw new RuntimeException('injected crash mid-swap');
                }
            }
        });

        try {
            $engine->apply();
            $this->fail('expected the injected mid-swap crash');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        // The site is EXACTLY at its pre-apply state — nothing half-applied, nothing broken.
        $this->assertSame($preApply, $this->snapshot(self::DIR), 'site must be byte-identical to pre-apply after rollback');

        // No maintenance flag left behind, staging/trash cleaned up.
        $this->assertFileDoesNotExist($this->abspath.'/.maintenance');
        $this->assertDirDoesNotExist($this->abspath.'/'.SAM_Backup_Restore_Engine::STAGE_PREFIX.$engine->token());
        $this->assertDirDoesNotExist($this->abspath.'/'.SAM_Backup_Restore_Engine::TRASH_PREFIX.$engine->token());
    }

    // ── Test 4: rollback AFTER a successful apply returns to pre-apply ──

    public function test_rollback_after_successful_apply_returns_to_pre_apply(): void
    {
        $backup = [
            self::DIR.'/a.txt' => 'ORIGINAL-A',
            self::DIR.'/b.txt' => 'ORIGINAL-B',
        ];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $this->writeLiveFile(self::DIR.'/a.txt', 'MODIFIED-A');
        $this->writeLiveFile(self::DIR.'/live-only.txt', 'LIVE-ONLY');
        $preApply = $this->snapshot(self::DIR);

        $engine = $this->engine('rb');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        // Applied: a restored, live-only removed.
        $this->assertLive(self::DIR.'/a.txt', 'ORIGINAL-A');
        $this->assertFileDoesNotExist($this->abspath.'/'.self::DIR.'/live-only.txt');

        // Rollback (e.g. post-restore validation failed) → back to pre-apply exactly.
        $engine->rollback();
        $this->assertSame($preApply, $this->snapshot(self::DIR), 'rollback must restore pre-apply state');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function engine(string $token): SAM_Backup_Restore_Engine
    {
        return new SAM_Backup_Restore_Engine($token, $this->abspath, $this->workDir.'/'.$token, []);
    }

    /**
     * @param  array<string,string>  $files
     */
    private function writeLive(array $files): void
    {
        foreach ($files as $rel => $content) {
            $this->writeLiveFile($rel, $content);
        }
    }

    private function writeLiveFile(string $rel, string $content): void
    {
        $abs = $this->abspath.'/'.$rel;
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, $content);
    }

    /**
     * @param  array<string,string>  $files  rel-path => content
     */
    private function makeChunkZip(string $name, array $files): string
    {
        $path = $this->chunkDir.'/'.$name.'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $rel => $content) {
            $zip->addFromString($rel, $content);
        }
        $zip->close();

        return $path;
    }

    private function assertLive(string $rel, string $expected): void
    {
        $abs = $this->abspath.'/'.$rel;
        $this->assertFileExists($abs, "expected live file present: {$rel}");
        $this->assertSame($expected, (string) file_get_contents($abs), "content mismatch for {$rel}");
    }

    /**
     * @return list<string> relative paths under $dir (sorted)
     */
    private function liveTree(string $dir): array
    {
        $root = $this->abspath.'/'.$dir;
        if (! is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $prefix = strlen($this->abspath) + 1;
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), $prefix);
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return array<string,string> rel-path => sha256 (a byte-exact snapshot of a subtree)
     */
    private function snapshot(string $dir): array
    {
        $out = [];
        foreach ($this->liveTree($dir) as $rel) {
            $out[$rel] = hash_file('sha256', $this->abspath.'/'.$rel);
        }

        return $out;
    }

    private function assertDirDoesNotExist(string $dir): void
    {
        $this->assertFalse(is_dir($dir), "dir should not exist: {$dir}");
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
