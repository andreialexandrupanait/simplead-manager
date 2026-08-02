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

    // ── Test 5: finishing a restore gives the host its disk back ──

    /**
     * The chunks the manager pushes are a full copy of the restore point, and nothing ever deleted
     * them: commit() cleaned the two directories inside the site and stopped there, while cleanup()
     * — which removes the session directory — had no callers at all. Every restore therefore cost
     * the client's server the size of its own site, permanently. Measured on the first real one:
     * 500 MB of free disk that never came back.
     */
    public function test_commit_gives_back_the_disk_the_staged_chunks_were_holding(): void
    {
        $backup = [self::DIR.'/a.txt' => 'ORIGINAL-A'];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('leak');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $this->assertFileExists($this->workDir.'/leak/chunks/files/chunk_0.zip');

        $engine->apply();
        $engine->commit();

        $this->assertDirDoesNotExist($this->workDir.'/leak/chunks');
    }

    /**
     * A restore that had to be undone is the same story: the chunks are no longer of any use to
     * anyone, and a rollback often happens on a host that was short of room to begin with.
     */
    public function test_rollback_also_gives_back_the_disk(): void
    {
        $backup = [self::DIR.'/a.txt' => 'ORIGINAL-A'];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('leak-rb');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();
        $engine->rollback();

        $this->assertDirDoesNotExist($this->workDir.'/leak-rb/chunks');
    }

    /**
     * ...but not the state files. The manager reads `restore/status` AFTER the fact — a redelivered
     * job asking "did this already finish?" gets its answer from status.json, and a wholesale
     * cleanup() would have it answered `unknown`, i.e. indistinguishable from a restore that never
     * ran. That is the reconciliation path this engine's own async apply depends on.
     */
    public function test_the_status_survives_the_tidying_up(): void
    {
        $backup = [self::DIR.'/a.txt' => 'ORIGINAL-A'];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('status-lives');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();
        $engine->commit();

        $this->assertSame('committed', $engine->status()['state'] ?? null);

        // And a second engine over the same directory — which is what the status endpoint builds
        // on the next request — reads the same thing.
        $this->assertSame('committed', $this->engine('status-lives')->status()['state'] ?? null);
    }

    // ── Test 6: the restore no longer needs a second copy of the site ──

    /**
     * The engine used to extract every chunk into one staging tree before swapping anything, so at
     * the peak the client's server held three copies of the site: the pushed chunks, the extracted
     * tree, and the trash. Only the trash is load-bearing — it *is* the rollback. Staging is now
     * one chunk at a time, and each chunk is dropped once its files are live.
     */
    public function test_staging_never_holds_more_than_one_chunk_at_a_time(): void
    {
        $chunks = [];
        $keep = [];
        for ($c = 0; $c < 4; $c++) {
            $files = [];
            for ($f = 0; $f < 3; $f++) {
                $rel = self::DIR."/c{$c}-f{$f}.bin";
                $files[$rel] = str_repeat("chunk{$c}", 4096); // ~28 KB each
                $keep[] = $rel;
            }
            $chunks[] = $this->makeChunkZip("files{$c}", $files);
        }

        $engine = $this->engine('peak');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => $keep,
        ]);
        foreach ($chunks as $seq => $path) {
            $engine->stage_files_chunk($seq, $path, hash_file('sha256', $path));
        }

        $stagingRoot = $this->abspath.'/'.SAM_Backup_Restore_Engine::STAGE_PREFIX.'peak';
        $peakStaged = 0;
        $lastSeenChunks = PHP_INT_MAX;
        $engine->set_fault(function () use ($stagingRoot, &$peakStaged, &$lastSeenChunks): void {
            $peakStaged = max($peakStaged, $this->dirSize($stagingRoot));
            $lastSeenChunks = $this->dirSize($this->workDir.'/peak/chunks/files');
        });

        $engine->apply();

        // Compared against the size on disk once extracted, which is what the host actually has
        // to find room for — the zips themselves compress to almost nothing here.
        $perFile = 3 * 4096 * strlen('chunk0');
        $oneChunk = 3 * $perFile;
        $whole = count($chunks) * $oneChunk;

        $this->assertLessThan(
            $whole,
            $peakStaged,
            'staging must never grow to the size of the whole restore point',
        );
        $this->assertLessThanOrEqual(
            $oneChunk,
            $peakStaged,
            'staging should hold one chunk, not the site',
        );
        // And the chunks shrink as they are consumed: by the last swap, most of them are gone.
        $this->assertLessThan(
            $this->dirSizeOf($chunks),
            $lastSeenChunks,
            'a consumed chunk should be gone, not held until the restore ends',
        );
        $this->assertSame(0, $this->dirSize($this->workDir.'/peak/chunks/files'));
    }

    public function test_each_chunk_is_dropped_once_its_files_are_live(): void
    {
        $backup = [self::DIR.'/a.txt' => 'ORIGINAL-A'];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('drop');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        $this->assertFileDoesNotExist($this->workDir.'/drop/chunks/files/chunk_0.zip');
        $this->assertLive(self::DIR.'/a.txt', 'ORIGINAL-A');
    }

    // ── Test 7: a path carried by two chunks ──

    /**
     * Later chunk wins, and — the part that per-chunk swapping could easily get wrong — the trash
     * must still hold the file that was live before the restore, not the intermediate version an
     * earlier chunk put there. Otherwise a rollback quietly returns the site to a state it was
     * never in.
     */
    public function test_a_path_in_two_chunks_takes_the_later_one_and_rollback_still_finds_the_original(): void
    {
        $rel = self::DIR.'/shared.txt';
        $this->writeLiveFile($rel, 'WHAT-WAS-LIVE');

        $first = $this->makeChunkZip('files0', [$rel => 'FROM-CHUNK-0']);
        $second = $this->makeChunkZip('files1', [$rel => 'FROM-CHUNK-1']);

        $engine = $this->engine('dupe');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => [$rel],
        ]);
        $engine->stage_files_chunk(0, $first, hash_file('sha256', $first));
        $engine->stage_files_chunk(1, $second, hash_file('sha256', $second));
        $engine->apply();

        $this->assertLive($rel, 'FROM-CHUNK-1');

        $engine->rollback();
        $this->assertLive($rel, 'WHAT-WAS-LIVE');
    }

    // ── Test 8: MIRROR deletions come from the manifest, not from what arrived ──

    /**
     * The delete set used to be "everything live that is not in staging", which reads a chunk that
     * failed to carry a file as proof the backup does not contain it — and deletes the live copy.
     * The manifest says what the backup contains; that is what MIRROR must compare against.
     */
    public function test_mirror_does_not_delete_a_live_file_the_manifest_promises(): void
    {
        $this->writeLiveFile(self::DIR.'/a.txt', 'LIVE-A');
        $this->writeLiveFile(self::DIR.'/promised.txt', 'LIVE-PROMISED');
        $this->writeLiveFile(self::DIR.'/extra.txt', 'NOT-IN-BACKUP');

        // The chunk carries a.txt but — a torn upload, a builder that skipped it — not promised.txt.
        $chunk = $this->makeChunkZip('files0', [self::DIR.'/a.txt' => 'BACKUP-A']);

        $engine = $this->engine('mirror-promise');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [self::DIR],
            'keep_paths' => [self::DIR.'/a.txt', self::DIR.'/promised.txt'],
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        $this->assertLive(self::DIR.'/a.txt', 'BACKUP-A');
        $this->assertLive(self::DIR.'/promised.txt', 'LIVE-PROMISED');
        // ...while a file the manifest genuinely does not name is still removed, as MIRROR means.
        $this->assertFileDoesNotExist($this->abspath.'/'.self::DIR.'/extra.txt');
    }

    // ── Test 9: the restore reports what it actually cost the host ──

    /**
     * The peak footprint is the one number about a restore that cannot be measured from outside:
     * the apply runs with the site in maintenance, where WordPress answers 503 to everything —
     * including this plugin's own capabilities endpoint. Every figure we had was a prediction from
     * the manager's preflight, and nobody could check it.
     */
    public function test_the_apply_reports_the_disk_it_used(): void
    {
        $backup = [];
        for ($f = 0; $f < 6; $f++) {
            $backup[self::DIR."/m{$f}.bin"] = str_repeat("payload{$f}", 2048);
        }
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('watermark');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));

        $result = $engine->apply();

        $this->assertArrayHasKey('disk', $result, 'the apply must say what it cost');

        foreach (['temp', 'site'] as $fs) {
            $this->assertArrayHasKey($fs, $result['disk'], "both filesystems are measured: {$fs}");
            $report = $result['disk'][$fs];

            $this->assertGreaterThan(1, $report['samples'], 'a single sample is a reading, not a peak');
            $this->assertNotNull($report['baseline_bytes']);
            $this->assertLessThanOrEqual(
                $report['baseline_bytes'],
                $report['min_free_bytes'],
                'free space cannot end up above where it started within one apply',
            );
            $this->assertSame(
                $report['baseline_bytes'] - $report['min_free_bytes'],
                $report['peak_used_bytes'],
                'peak used is exactly what the watermark says',
            );
        }
    }

    /**
     * ...and it survives to be asked about afterwards. The manager reads the status after the fact —
     * that is the whole reconciliation path — so a measurement that only exists in the apply's
     * return value is one a redelivered job can never see.
     */
    public function test_the_measurement_is_still_there_after_the_apply(): void
    {
        $backup = [self::DIR.'/a.txt' => 'ORIGINAL-A'];
        $this->writeLive($backup);
        $chunk = $this->makeChunkZip('files0', $backup);

        $engine = $this->engine('watermark-status');
        $engine->prepare([
            'mode' => SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'mirror_roots' => [self::DIR],
            'keep_paths' => array_keys($backup),
        ]);
        $engine->stage_files_chunk(0, $chunk, hash_file('sha256', $chunk));
        $engine->apply();

        $status = $this->engine('watermark-status')->status();

        $this->assertSame('applied', $status['state'] ?? null);
        $this->assertArrayHasKey('site', $status['disk'] ?? []);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param  array<int,string>  $paths */
    private function dirSizeOf(array $paths): int
    {
        return array_sum(array_map(static fn (string $p): int => (int) @filesize($p), $paths));
    }

    private function dirSize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

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
