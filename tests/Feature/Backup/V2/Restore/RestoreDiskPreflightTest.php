<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Exceptions\PreflightFailed;
use App\Backup\V2\Restore\RestoreDiskPreflight;
use App\Backup\V2\Restore\RestorePlan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The check nobody was doing: is there room on the client's server for this restore?
 *
 * Neither `restore/prepare` nor the runner asked, though the engine this replaced did. The failure
 * that follows from not asking is not a refused restore — it is a restore that stops half way
 * through the swap, with the site in maintenance and the rollback needing space of its own to move
 * files back.
 */
#[Group('restore')]
class RestoreDiskPreflightTest extends TestCase
{
    private const GB = 1024 ** 3;

    public function test_it_refuses_before_anything_is_transferred_and_says_by_how_much(): void
    {
        // A 4 GB restore point on a host with 2 GB left.
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: (int) (0.5 * self::GB));

        try {
            (new RestoreDiskPreflight)->assertRoomFor($plan, $this->host(tempFree: 2 * self::GB, siteFree: 2 * self::GB, same: true));
            $this->fail('a restore that does not fit must be refused');
        } catch (PreflightFailed $e) {
            $this->assertSame(BackupErrorCode::DiskFull, $e->errorCode);
            // The numbers belong in the message: "not enough space" without them is an invitation
            // to guess, and whoever reads it is usually deciding whether to free something up.
            $this->assertStringContainsString('2 GB free', $e->getMessage());
            $this->assertStringContainsString('needs', $e->getMessage());
            $this->assertStringContainsString('Nothing was transferred', $e->getMessage());
        }
    }

    public function test_a_restore_that_fits_is_allowed_through(): void
    {
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: (int) (0.5 * self::GB));

        $report = (new RestoreDiskPreflight)->assertRoomFor(
            $plan,
            $this->host(tempFree: 40 * self::GB, siteFree: 40 * self::GB, same: true),
        );

        $this->assertTrue($report['checked']);
        $this->assertSame(4 * self::GB, $report['file_bytes']);
    }

    public function test_two_filesystems_are_measured_separately(): void
    {
        // Plenty of room in temp for the chunks, almost none where the site — and therefore the
        // trash, and therefore the rollback — actually lives.
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: (int) (0.5 * self::GB));

        $this->expectException(PreflightFailed::class);
        $this->expectExceptionMessageMatches('/site filesystem/');

        (new RestoreDiskPreflight)->assertRoomFor(
            $plan,
            $this->host(tempFree: 100 * self::GB, siteFree: 3 * self::GB, same: false),
        );
    }

    public function test_one_filesystem_has_to_hold_both_demands_at_once(): void
    {
        // 6 GB free, and separately each side would pass: chunks want ~4.8 GB, the site ~5.4 GB.
        // Together they want both, out of the same pool, and this is the case that used to slip
        // through by checking one number twice.
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: (int) (0.5 * self::GB));

        $this->expectException(PreflightFailed::class);

        (new RestoreDiskPreflight)->assertRoomFor(
            $plan,
            $this->host(tempFree: 6 * self::GB, siteFree: 6 * self::GB, same: true),
        );
    }

    public function test_an_older_plugin_that_reports_only_temp_is_treated_as_one_filesystem(): void
    {
        // No site figure and no `same_filesystem`: assume they share, which asks for more room
        // rather than less. Being conservative here costs a refusal; being optimistic costs a site.
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: (int) (0.5 * self::GB));

        $this->expectException(PreflightFailed::class);

        (new RestoreDiskPreflight)->assertRoomFor($plan, ['disk' => [
            'temp_dir' => '/tmp',
            'free_bytes' => 6 * self::GB,
        ]]);
    }

    public function test_a_host_that_will_not_say_is_recorded_rather_than_refused(): void
    {
        // open_basedir, or a filesystem disk_free_space cannot read. Grounding restores over a
        // question the host declines to answer would be the wrong trade — the same call the backup
        // preflight makes about the same silence.
        $plan = $this->planOf(fileBytes: 400 * self::GB, largestChunk: self::GB);

        $report = (new RestoreDiskPreflight)->assertRoomFor($plan, ['disk' => [
            'temp_dir' => '/tmp',
            'free_bytes' => null,
            'site_free_bytes' => null,
        ]]);

        $this->assertFalse($report['checked']);
        $this->assertStringContainsString('did not report', (string) $report['reason']);
    }

    public function test_the_margin_is_part_of_the_requirement(): void
    {
        // Exactly enough by raw arithmetic (4 GB point, one filesystem → 8 GB), nothing spare.
        // disk_free_space does not see quotas or whatever else the host is doing with that disk.
        $plan = $this->planOf(fileBytes: 4 * self::GB, largestChunk: 0);

        $this->expectException(PreflightFailed::class);
        $this->expectExceptionMessageMatches('/20% margin/');

        (new RestoreDiskPreflight)->assertRoomFor(
            $plan,
            $this->host(tempFree: 8 * self::GB, siteFree: 8 * self::GB, same: true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function host(int $tempFree, int $siteFree, bool $same): array
    {
        return ['disk' => [
            'temp_dir' => '/tmp/sam_backup',
            'free_bytes' => $tempFree,
            'site_dir' => '/var/www/html',
            'site_free_bytes' => $siteFree,
            'same_filesystem' => $same,
        ]];
    }

    private function planOf(int $fileBytes, int $largestChunk): RestorePlan
    {
        return new RestorePlan(
            fileChunks: [],
            keepPaths: [],
            dbChunks: [],
            tombstones: [],
            dbTables: [],
            mirrorRoots: [],
            includeDatabase: false,
            includeFiles: true,
            fileBytes: $fileBytes,
            largestChunkBytes: $largestChunk,
        );
    }
}
