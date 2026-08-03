<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * The hourly sweep that collects what an interrupted restore left behind on the client's server.
 *
 * Its first version looked only inside ABSPATH — `sam-restore-trash-*` and `sam-restore-staging-*` —
 * and missed the expensive one: the session directory the manager pushes chunks into, which holds a
 * full copy of the restore point. commit() and rollback() drop the chunks now, but a restore that
 * never reached either (the manager gave up, the host was rebooted, the job was cancelled) still
 * leaves one behind, and nothing at all would have collected it.
 *
 * Runs in its own process: it must define ABSPATH and stub a couple of WordPress functions to load
 * the plugin bootstrap, and neither should be visible to the rest of the suite.
 */
#[Group('restore')]
#[RunTestsInSeparateProcesses]
class RestoreLeftoverSweepTest extends TestCase
{
    private string $abspath;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abspath = sys_get_temp_dir().'/sam_sweep_site_'.uniqid('', true);
        @mkdir($this->abspath, 0755, true);

        require_once __DIR__.'/../Support/wordpress-stubs.php';
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->abspath.'/');
        }

        $base = base_path('wordpress-plugin/simplead-backup');
        require_once $base.'/includes/support/class-temp.php';
        require_once $base.'/includes/support/class-logger.php';
        require_once $base.'/includes/support/class-sweep-policy.php';
        // The sweep names the staging/trash directories by the engine's own prefixes, and asks it
        // to drop the tables of sessions that are gone. Without a database here that second half
        // fails into its own catch, which is exactly what it is written to do.
        require_once $base.'/includes/restore/class-restore-engine.php';
        require_once $base.'/includes/class-backup-plugin.php';

        $this->tempRoot = \SAM_Backup_Temp::ensure('sessions');
    }

    protected function tearDown(): void
    {
        \SAM_Backup_Temp::remove_dir_under($this->abspath, sys_get_temp_dir());
        parent::tearDown();
    }

    public function test_it_collects_the_chunk_directory_an_abandoned_restore_left_in_temp(): void
    {
        $abandoned = $this->tempRoot.'/restore_'.uniqid('', true);
        @mkdir($abandoned, 0700, true);
        file_put_contents($abandoned.'/chunk_0.zip', 'a full copy of somebody else website');
        $this->age($abandoned, hours: 3);

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryDoesNotExist($abandoned);
    }

    public function test_it_still_collects_the_directories_inside_the_site(): void
    {
        $trash = $this->leftover(rtrim(ABSPATH, '/').'/sam-restore-trash-abandoned');
        $staging = $this->leftover(rtrim(ABSPATH, '/').'/sam-restore-staging-abandoned');

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryDoesNotExist($trash);
        $this->assertDirectoryDoesNotExist($staging);
    }

    public function test_it_leaves_a_restore_that_is_still_running_alone(): void
    {
        // The whole of a restore — staging, transfer, apply — is minutes, so an hour is a wide
        // margin. Sweeping a live session would delete the chunks out from under an apply.
        $fresh = $this->tempRoot.'/restore_'.uniqid('', true);
        @mkdir($fresh, 0700, true);
        file_put_contents($fresh.'/chunk_0.zip', 'in flight');

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryExists($fresh);
        \SAM_Backup_Temp::remove_dir($fresh);
    }

    public function test_it_does_not_touch_a_backup_session_sharing_the_same_root(): void
    {
        // Backups keep their DB dump under the same sessions/ root. A sweep that matched everything
        // there would delete a dump mid-upload.
        $backup = $this->leftover($this->tempRoot.'/backup_'.uniqid('', true));

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryExists($backup);
        \SAM_Backup_Temp::remove_dir($backup);
    }

    /**
     * The bug this file exists to prevent a second time.
     *
     * A directory's own mtime freezes when it is created — everything an apply writes goes one
     * level down — so a restore that ran past the hour looked abandoned. The sweep then deleted
     * the trash holding the only copy of every live file the restore had displaced, and the
     * rollback that followed had nothing to put back and said it had succeeded.
     */
    public function test_it_leaves_a_long_running_apply_alone(): void
    {
        $session = $this->tempRoot.'/restore_slow';
        @mkdir($session, 0700, true);
        file_put_contents($session.'/status.json', json_encode(['token' => 'slow', 'state' => 'applying']));

        // Three hours in, and the directory itself has not been touched since it was made.
        $this->age($session, hours: 3);
        @touch($session.'/status.json', time() - 600);

        $trash = rtrim(ABSPATH, '/').'/sam-restore-trash-slow';
        $this->leftover($trash);

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryExists($session, 'the session of a running apply must survive');
        $this->assertDirectoryExists($trash, 'and so must the trash rollback depends on');

        \SAM_Backup_Temp::remove_dir($session);
    }

    public function test_a_committed_session_is_still_collected_after_an_hour(): void
    {
        $session = $this->tempRoot.'/restore_done';
        @mkdir($session, 0700, true);
        file_put_contents($session.'/status.json', json_encode(['token' => 'done', 'state' => 'committed']));
        $this->age($session, hours: 3);
        @touch($session.'/status.json', time() - (3 * 3600));

        $trash = $this->leftover(rtrim(ABSPATH, '/').'/sam-restore-trash-done');

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryDoesNotExist($session);
        $this->assertDirectoryDoesNotExist($trash, 'the session governs the directories carrying its token');
    }

    public function test_staging_a_large_site_is_given_longer_than_an_hour(): void
    {
        $session = $this->tempRoot.'/restore_staging';
        @mkdir($session, 0700, true);
        file_put_contents($session.'/status.json', json_encode(['token' => 'staging', 'state' => 'staging']));
        $this->age($session, hours: 3);
        @touch($session.'/status.json', time() - (3 * 3600));

        \SAM_Backup_Plugin::instance()->sweep_restore_leftovers();

        $this->assertDirectoryExists($session);
        \SAM_Backup_Temp::remove_dir($session);
    }

    /** A directory with a file in it, old enough that the sweep is entitled to take it. */
    private function leftover(string $dir): string
    {
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/something', 'left behind');

        return $this->age($dir, hours: 3);
    }

    /**
     * Age the directory itself, last: writing anything inside it resets its mtime, which is what
     * the sweep reads.
     */
    private function age(string $dir, int $hours): string
    {
        @touch($dir, time() - ($hours * 3600));

        return $dir;
    }
}
