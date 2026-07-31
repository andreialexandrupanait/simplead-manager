<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Exceptions\PreflightFailed;
use App\Backup\V2\Orchestration\PreflightCheck;
use App\Backup\V2\Orchestration\ResourceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Stop before touching the site, not after.
 *
 * The capability probe asked the host all of this and acted on almost none of
 * it: free disk, the zip extension the chunker depends on, whether the temp
 * directory can be written to, the plugin's own version — all reported, all
 * ignored. The only decision it made was to note that consistent snapshots were
 * unavailable and carry on regardless.
 *
 * What that costs is not a failed backup, it is a backup that fails LATE: after
 * the plugin has walked the filesystem, after temp files have been written to a
 * disk that was already nearly full, after the site has spent CPU building
 * chunks that were never going to be uploaded.
 */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    /** A host that passes every check, to vary one thing at a time from. */
    private function healthyHost(array $overrides = []): array
    {
        return array_replace_recursive([
            'plugin' => ['version' => '0.5.0'],
            'extensions' => ['zip' => true],
            'disk' => ['temp_dir' => '/tmp/sam_backup', 'temp_writable' => true, 'free_bytes' => 5 * 1024 ** 3],
        ], $overrides);
    }

    private function assertRefuses(array $caps, BackupErrorCode $code, string $needle, int $minFreeMb = 512): void
    {
        try {
            (new PreflightCheck)->assertHostIsReady($caps, $minFreeMb);
            $this->fail('preflight should have refused this host');
        } catch (PreflightFailed $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_a_healthy_host_passes(): void
    {
        (new PreflightCheck)->assertHostIsReady($this->healthyHost(), 512);
        $this->expectNotToPerformAssertions();
    }

    public function test_a_host_without_room_for_one_chunk_is_refused(): void
    {
        $this->assertRefuses(
            $this->healthyHost(['disk' => ['free_bytes' => 40 * 1024 * 1024]]),
            BackupErrorCode::DiskFull,
            '40 MB free',
        );
    }

    /**
     * Free space that the host would not report is a question we failed to ask,
     * not an answer. Refusing on it would ground sites for an open_basedir
     * setting.
     */
    public function test_disk_space_the_host_will_not_report_is_not_a_refusal(): void
    {
        (new PreflightCheck)->assertHostIsReady(
            $this->healthyHost(['disk' => ['free_bytes' => null]]),
            512,
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_an_old_plugin_is_named_rather_than_left_to_fail_later(): void
    {
        $this->assertRefuses(
            $this->healthyHost(['plugin' => ['version' => '0.3.0']]),
            BackupErrorCode::ManifestInvalid,
            '0.3.0',
        );
    }

    public function test_a_host_with_no_plugin_at_all_is_refused(): void
    {
        $caps = $this->healthyHost();
        unset($caps['plugin']);

        $this->assertRefuses($caps, BackupErrorCode::ManifestInvalid, 'did not report a backup plugin version');
    }

    /**
     * Every file chunk is a zip the plugin builds. Without the extension the
     * chunker dies on chunk zero, after the inventory has already walked the
     * whole tree.
     */
    public function test_a_host_without_ext_zip_is_refused(): void
    {
        $this->assertRefuses(
            $this->healthyHost(['extensions' => ['zip' => false]]),
            BackupErrorCode::EngineError,
            'no zip extension',
        );
    }

    /**
     * The exact failure the lab hit: a temp directory owned by another user —
     * which is what a root-run wp-cli command leaves behind — makes every dump
     * and every staged restore answer 500 with nothing but a PHP warning in the
     * host's error log.
     */
    public function test_a_temp_directory_the_host_cannot_write_to_is_refused(): void
    {
        $this->assertRefuses(
            $this->healthyHost(['disk' => ['temp_writable' => false]]),
            BackupErrorCode::DiskFull,
            '/tmp/sam_backup',
        );
    }

    /** Older plugin builds do not report it; absence is not failure. */
    public function test_a_plugin_that_does_not_report_writability_is_not_refused(): void
    {
        $caps = $this->healthyHost();
        unset($caps['disk']['temp_writable']);

        (new PreflightCheck)->assertHostIsReady($caps, 512);
        $this->expectNotToPerformAssertions();
    }

    // ── the profile ──────────────────────────────────────────────────────

    /**
     * low_impact and fast produced byte-identical behaviour: six config keys,
     * not one reader. Every value now reaches a parameter the plugin honours.
     */
    public function test_the_profiles_are_actually_different(): void
    {
        $low = ResourceProfile::named('low_impact');
        $fast = ResourceProfile::named('fast');

        $this->assertLessThan($fast->fileChunkBytes, $low->fileChunkBytes);
        $this->assertLessThan($fast->dbBatchRows, $low->dbBatchRows);
        $this->assertGreaterThan($fast->pauseMs, $low->pauseMs);
        $this->assertSame('store', $low->compression);
        $this->assertSame('deflate', $fast->compression);
    }

    public function test_an_unknown_profile_falls_back_to_the_gentlest_one(): void
    {
        $this->assertSame(
            ResourceProfile::named('low_impact')->fileChunkBytes,
            ResourceProfile::named('does-not-exist')->fileChunkBytes,
        );
    }

    public function test_the_disk_threshold_comes_from_the_profile(): void
    {
        Config::set('backup_v2.profiles.low_impact.min_free_disk_mb', 4096);

        $this->assertRefuses(
            $this->healthyHost(['disk' => ['free_bytes' => 1024 ** 3]]),
            BackupErrorCode::DiskFull,
            '4 GB',
            ResourceProfile::named('low_impact')->minFreeDiskMb,
        );
    }
}
