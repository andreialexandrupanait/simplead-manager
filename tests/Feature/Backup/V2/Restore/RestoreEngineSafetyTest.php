<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The file half of SAM_Backup_Restore_Engine, driven directly against a temporary site root.
 *
 * Every case here is a bug that reached production and lost a client's files quietly: a restore
 * that reported success while a file was missing, a MIRROR pass that deleted a file for having two
 * dots in its name, a rollback that answered {ok:true} after destroying what it was meant to put
 * back. They are cheap to assert and were expensive to find, so they are pinned.
 *
 * Runs in its own process: the engine needs ABSPATH defined and a couple of WordPress functions
 * stubbed, and neither should leak into the rest of the suite.
 */
#[Group('restore')]
#[RunTestsInSeparateProcesses]
class RestoreEngineSafetyTest extends TestCase
{
    private string $site;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/sam_restore_engine_'.uniqid('', true);
        $this->site = $root.'/site';
        $this->work = $root.'/work';
        @mkdir($this->site, 0755, true);
        @mkdir($this->work, 0755, true);

        require_once __DIR__.'/../Support/wordpress-stubs.php';
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->site.'/');
        }

        require_once base_path('wordpress-plugin/simplead-backup/includes/restore/class-restore-engine.php');
    }

    protected function tearDown(): void
    {
        $this->rmrf(dirname($this->site));
        parent::tearDown();
    }

    /**
     * A file called `logo..png` is a file, not a traversal attempt.
     *
     * is_safe_relative() tested for '..' anywhere in the string, so any such name was dropped from
     * the restore set — and in MIRROR, "not in the restore set" means "absent from the backup",
     * which means moved to the trash and deleted at commit. A file present in BOTH the live site
     * and the backup was destroyed for how it was spelled.
     */
    public function test_a_filename_containing_two_dots_survives_a_mirror_restore(): void
    {
        $this->liveFile('wp-content/uploads/logo..png', 'the original bytes');
        $this->liveFile('wp-content/uploads/keep.txt', 'also here');

        $engine = $this->engine('mirror-dots');
        $engine->prepare([
            'mode' => \SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => ['wp-content/uploads'],
            'keep_paths' => ['wp-content/uploads/logo..png', 'wp-content/uploads/keep.txt'],
        ]);

        $this->stageChunk($engine, 0, [
            'wp-content/uploads/logo..png' => 'restored bytes',
            'wp-content/uploads/keep.txt' => 'also here',
        ]);

        $engine->apply();

        $this->assertFileExists($this->site.'/wp-content/uploads/logo..png');
        $this->assertSame('restored bytes', file_get_contents($this->site.'/wp-content/uploads/logo..png'));
    }

    /**
     * MIRROR walks the webroot, and the staging and trash directories live inside it — they have
     * to, because a swap must be a rename on one filesystem. Nothing excluded them, so MIRROR
     * queued the trash for deletion into the trash, and the engine's own directory with it.
     */
    public function test_mirror_over_the_whole_webroot_does_not_delete_its_own_working_directories(): void
    {
        $this->liveFile('index.php', 'live');
        $this->liveFile('wp-content/plugins/simplead-backup/simplead-backup.php', 'the engine itself');

        $engine = $this->engine('mirror-self');
        $engine->prepare([
            'mode' => \SAM_Backup_Restore_Engine::MODE_MIRROR,
            'mirror_roots' => [''],
            'keep_paths' => ['index.php'],
        ]);

        $this->stageChunk($engine, 0, ['index.php' => 'restored']);
        $engine->apply();

        $this->assertFileExists(
            $this->site.'/wp-content/plugins/simplead-backup/simplead-backup.php',
            'MIRROR must never delete the engine running it'
        );
        $this->assertDirectoryExists($this->site.'/sam-restore-trash-mirror-self', 'nor the trash rollback needs');
    }

    /**
     * A chunk that cannot be fully extracted must fail the apply, not be skipped.
     *
     * extract_zip_into() used to `continue` past an unreadable entry, and the caller deleted the
     * chunk zip regardless — so the file was absent from the restored site, its only copy was
     * gone, and apply() returned {ok:true, applied:true}.
     */
    public function test_an_entry_that_cannot_be_extracted_fails_the_apply_and_restores_the_tree(): void
    {
        $this->liveFile('wp-content/uploads/a.txt', 'original A');
        $this->liveFile('wp-content/uploads/b.txt', 'original B');

        $engine = $this->engine('bad-chunk');
        $engine->prepare([
            'mode' => \SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'keep_paths' => [
                'wp-content/uploads/a.txt',
                'wp-content/uploads/a.txt/nested.txt',
                'wp-content/uploads/b.txt',
            ],
        ]);

        // The second entry cannot be written: its parent directory is the file the first entry
        // just created. Contrived, but it is the same branch a full disk, an open_basedir rule or
        // a permission takes — and that branch used to be a bare `continue`.
        $this->stageChunk($engine, 0, [
            'wp-content/uploads/a.txt' => 'restored A',
            'wp-content/uploads/a.txt/nested.txt' => 'unwritable',
            'wp-content/uploads/b.txt' => 'restored B',
        ]);

        try {
            $engine->apply();
            $this->fail('apply() must not report success when an entry cannot be extracted');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/staging root|could not open|short write|size mismatch/i', $e->getMessage());
        }

        $this->assertSame('original A', file_get_contents($this->site.'/wp-content/uploads/a.txt'));
        $this->assertSame('original B', file_get_contents($this->site.'/wp-content/uploads/b.txt'));
    }

    /**
     * A chunk redelivered after the apply consumed it used to rewrite the status back to
     * `staging` — erasing `applied`, disarming the re-entry guard, and re-opening the door to a
     * second apply over the first one's rollback data.
     */
    public function test_a_chunk_pushed_after_the_apply_is_refused(): void
    {
        $this->liveFile('index.php', 'live');

        $engine = $this->engine('late-chunk');
        $engine->prepare(['mode' => \SAM_Backup_Restore_Engine::MODE_SAFE_MERGE, 'keep_paths' => ['index.php']]);
        $this->stageChunk($engine, 0, ['index.php' => 'restored']);
        $engine->apply();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/can no longer be staged/');

        $this->stageChunk($engine, 0, ['index.php' => 'restored again']);
    }

    /**
     * A retry after a failure that already swapped the database must be refused.
     *
     * The second swap renames the ALREADY-RESTORED tables into the retained-original slot, so the
     * pre-restore data is gone and the rollback that follows returns the site to the restored
     * state it was trying to escape.
     */
    public function test_an_apply_is_refused_once_the_database_has_been_swapped(): void
    {
        $this->liveFile('index.php', 'live');

        $engine = $this->engine('db-swapped');
        $engine->prepare(['mode' => \SAM_Backup_Restore_Engine::MODE_SAFE_MERGE, 'keep_paths' => ['index.php']]);
        $this->stageChunk($engine, 0, ['index.php' => 'restored']);

        // As apply() would have left it: the DB phase recorded, the file phase never reached.
        file_put_contents(
            $this->work.'/apply-state.json',
            json_encode(['files' => null, 'db' => ['name_map' => [], 'old_map' => ['wp_posts' => 'sambk_old_x_wp_posts']]])
        );
        file_put_contents($this->work.'/status.json', json_encode(['state' => 'failed']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already swapped the database/');

        $engine->apply();
    }

    /**
     * rollback() used to read its steps only from apply-state.json, which is written after the
     * whole file phase returns — so a process killed mid-apply left it null and the rollback
     * concluded there were no files to put back, over a half-swapped tree. The journal on disk
     * knew all along.
     */
    public function test_rollback_replays_the_on_disk_journal_when_the_apply_never_recorded_itself(): void
    {
        $this->liveFile('wp-content/uploads/a.txt', 'original A');

        $engine = $this->engine('crashed');
        $engine->prepare([
            'mode' => \SAM_Backup_Restore_Engine::MODE_SAFE_MERGE,
            'keep_paths' => ['wp-content/uploads/a.txt'],
        ]);
        $this->stageChunk($engine, 0, ['wp-content/uploads/a.txt' => 'restored A']);
        $engine->apply();

        $this->assertSame('restored A', file_get_contents($this->site.'/wp-content/uploads/a.txt'));

        // Exactly what a killed worker leaves behind: the tree swapped, the journal on disk, and
        // no `files` in the apply state.
        file_put_contents($this->work.'/apply-state.json', json_encode(['files' => null, 'db' => null]));
        file_put_contents($this->work.'/status.json', json_encode(['state' => 'failed']));

        $engine->rollback();

        $this->assertSame(
            'original A',
            file_get_contents($this->site.'/wp-content/uploads/a.txt'),
            'the original must come back from the journal alone'
        );
    }

    /**
     * A claim staked for a dispatch that never ran used to pin the token forever: the synchronous
     * retry was refused by the apply guard, and rollback was refused too, so nothing could free it
     * short of deleting the session.
     */
    public function test_a_queued_claim_that_was_never_executed_stops_blocking_the_token(): void
    {
        $this->liveFile('index.php', 'live');

        $engine = $this->engine('wedged');
        $engine->prepare(['mode' => \SAM_Backup_Restore_Engine::MODE_SAFE_MERGE, 'keep_paths' => ['index.php']]);
        $this->stageChunk($engine, 0, ['index.php' => 'restored']);

        $this->claimQueued(secondsAgo: 5);
        try {
            $engine->apply();
            $this->fail('a fresh claim must still be respected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        }

        $this->claimQueued(secondsAgo: 600);
        $result = $engine->apply();

        $this->assertTrue($result['applied']);
        $this->assertSame('restored', file_get_contents($this->site.'/index.php'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function engine(string $token): \SAM_Backup_Restore_Engine
    {
        return new \SAM_Backup_Restore_Engine($token, $this->site, $this->work);
    }

    private function claimQueued(int $secondsAgo): void
    {
        file_put_contents($this->work.'/status.json', json_encode([
            'state' => 'applying',
            'phase' => 'queued',
            'queued_at' => gmdate('c', time() - $secondsAgo),
        ]));
    }

    private function liveFile(string $rel, string $contents): void
    {
        $path = $this->site.'/'.$rel;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
    }

    /**
     * @param  array<string,string>  $entries
     */
    private function stageChunk(\SAM_Backup_Restore_Engine $engine, int $seq, array $entries): void
    {
        $this->stageRaw($engine, $seq, $this->buildZip($entries));
    }

    private function stageRaw(\SAM_Backup_Restore_Engine $engine, int $seq, string $zipPath): void
    {
        $engine->stage_files_chunk($seq, $zipPath, hash_file('sha256', $zipPath));
    }

    /**
     * @param  array<string,string>  $entries
     */
    private function buildZip(array $entries): string
    {
        $path = $this->work.'/src_'.uniqid('', true).'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
