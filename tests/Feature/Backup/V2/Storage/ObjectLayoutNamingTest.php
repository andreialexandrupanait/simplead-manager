<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Where a backup lands in the bucket, and — the part that matters more — where an
 * existing one stays.
 *
 * The old layout was `clients/{client_id}/sites/{site_id}/backups/{backup_id}`:
 * addressable, but a listing showed `clients/3/sites/17/backups/…`, so finding one
 * site's backups meant already knowing two numeric ids. Numbers are how the
 * database refers to a site; they are not how a person looks for one.
 */
class ObjectLayoutNamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_backup_is_filed_under_its_domain_and_day(): void
    {
        $layout = ObjectLayout::forBackup(
            clientId: 3,
            siteId: 17,
            backupId: 1234,
            siteDomain: 'https://feaagalati.ro/',
            date: '2026-08-08',
            time: '03-12-05',
            type: 'full',
            trigger: 'scheduled',
        );

        $this->assertSame('mentenanta/feaagalati.ro/2026-08-08/03-12-05-full-scheduled', $layout->prefix());
        $this->assertSame(
            'mentenanta/feaagalati.ro/2026-08-08/03-12-05-full-scheduled/manifest.json',
            $layout->manifest(),
        );
    }

    /**
     * Two backups on one day are two named entries under one date, not a
     * collision. A shared prefix would mean an overwritten manifest, interleaved
     * chunks, and retention deleting the other backup's objects.
     */
    public function test_a_second_backup_the_same_day_gets_its_own_place(): void
    {
        $night = ObjectLayout::forBackup(0, 17, 1, 'feaagalati.ro', '2026-08-08', '03-12-05', 'full', 'scheduled');
        $byHand = ObjectLayout::forBackup(0, 17, 2, 'feaagalati.ro', '2026-08-08', '14-32-40', 'database', 'manual');

        $this->assertNotSame($night->prefix(), $byHand->prefix());
        $this->assertSame('mentenanta/feaagalati.ro/2026-08-08/14-32-40-database-manual', $byHand->prefix());

        // Both under the same day folder, which is the point of the shape.
        $this->assertStringStartsWith('mentenanta/feaagalati.ro/2026-08-08/', $night->prefix());
        $this->assertStringStartsWith('mentenanta/feaagalati.ro/2026-08-08/', $byHand->prefix());
    }

    /**
     * The same site must not end up under two folders because its URL is written
     * differently, and two different hosts must not collapse onto one.
     */
    public function test_the_domain_segment_is_stable_and_distinct(): void
    {
        $variants = ['https://www.feaagalati.ro', 'http://feaagalati.ro/', 'FEAAGALATI.RO'];

        foreach ($variants as $url) {
            $this->assertSame(
                'mentenanta/feaagalati.ro/2026-08-08/03-00-00-full-scheduled',
                ObjectLayout::forBackup(0, 1, 1, $url, '2026-08-08', '03-00-00', 'full', 'scheduled')->prefix(),
                "«{$url}» files under the same domain as the others",
            );
        }

        $this->assertNotSame(
            ObjectLayout::forBackup(0, 1, 1, 'm1-beauty.hu', '2026-08-08', '03-00-00', 'full', 'scheduled')->prefix(),
            ObjectLayout::forBackup(0, 2, 2, 'm1-beauty.at', '2026-08-08', '03-00-00', 'full', 'scheduled')->prefix(),
        );
    }

    /** A site with an unusable URL still gets its own root rather than sharing an empty one. */
    public function test_a_site_without_a_usable_url_still_gets_its_own_root(): void
    {
        $this->assertSame(
            'mentenanta/site-42/2026-08-08/03-00-00-full-scheduled',
            ObjectLayout::forBackup(0, 42, 1, '   ', '2026-08-08', '03-00-00', 'full', 'scheduled')->prefix(),
        );
    }

    /**
     * The guarantee that makes changing the template safe at all: a session that
     * has already written objects is read back verbatim, so the 121 backups
     * sitting under the old `clients/…` layout stay exactly as findable as they
     * were.
     */
    public function test_an_existing_backup_keeps_the_prefix_it_was_written_to(): void
    {
        $site = Site::factory()->create(['url' => 'https://feaagalati.ro']);
        $old = 'clients/3/sites/17/backups/900';

        $session = $this->makeSession($site, ['object_prefix' => $old]);

        $this->assertSame($old, SessionLayoutResolver::for($session->fresh(), $site)->prefix());
    }

    /**
     * A run that begins at 23:58 and finishes after midnight belongs to the night
     * it started. Re-resolving mid-run must answer the same prefix every time, or
     * the second half of a backup is written where the first half is not.
     */
    public function test_the_prefix_comes_from_when_the_run_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 23:58:30'));

        $site = Site::factory()->create(['url' => 'https://feaagalati.ro']);
        $backup = Backup::factory()->create(['site_id' => $site->id, 'trigger' => 'scheduled']);
        $session = $this->makeSession($site, ['backup_id' => $backup->id, 'object_prefix' => null]);

        $first = SessionLayoutResolver::for($session->fresh(), $site)->prefix();
        $this->assertSame('mentenanta/feaagalati.ro/2026-08-08/23-58-30-full-scheduled', $first);

        // Twelve minutes later, the following day: same answer.
        Carbon::setTestNow(Carbon::parse('2026-08-09 00:10:00'));
        $this->assertSame($first, SessionLayoutResolver::for($session->fresh(), $site)->prefix());
    }

    /** @param array<string, mixed> $overrides */
    private function makeSession(Site $site, array $overrides = []): BackupSession
    {
        return BackupSession::create(array_merge([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'layout-'.uniqid('', true),
            'format_version' => 'simplead-backup/2',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
