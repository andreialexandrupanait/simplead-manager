<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Models\Backup;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A backup's objects live wherever they were first written. Recomputing the
 * prefix later does not move them — it only stops anything finding them.
 *
 * Four places used to compute it independently, from `backup_id ?? id`. Three
 * agreed by coincidence; the fourth looked somewhere nothing had ever written
 * and reported a healthy backup as corrupt. Worse, the agreed formula was not
 * stable: populating `backup_id` to link a session to its V1 row — which the
 * product plan requires — changes what it returns, so a column update made for
 * an unrelated reason would orphan every object already stored.
 */
class SessionLayoutResolverTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    /**
     * The regression this exists to prevent, stated as plainly as it can be.
     */
    public function test_the_prefix_does_not_move_when_backup_id_is_populated(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        $before = SessionLayoutResolver::for($session, $site)->prefix();

        // The link the product plan calls for — made for reasons that have
        // nothing to do with storage.
        $session->backup_id = Backup::factory()->completed()->create(['site_id' => $site->id])->id;
        $session->save();

        $after = SessionLayoutResolver::for($session->fresh(), $site)->prefix();

        $this->assertSame($before, $after, 'Linking a session to its V1 row must not relocate its objects.');
    }

    public function test_the_prefix_is_frozen_on_first_use(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site);

        $this->assertNull($session->object_prefix);

        $prefix = SessionLayoutResolver::for($session, $site)->prefix();

        $this->assertSame($prefix, $session->fresh()->object_prefix);
    }

    /**
     * Once stored, the prefix is read rather than derived — so editing the
     * template cannot strand backups already taken under the old one.
     */
    public function test_a_stored_prefix_survives_a_template_change(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site);

        $before = SessionLayoutResolver::for($session, $site)->prefix();

        config(['backup_v2.object_prefix' => 'totally/different/{site_id}/{backup_id}']);

        $this->assertSame($before, SessionLayoutResolver::for($session->fresh(), $site)->prefix());
    }

    public function test_a_new_session_follows_the_current_template(): void
    {
        config(['backup_v2.object_prefix' => 'archive/{site_id}/{backup_id}']);

        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site);

        $this->assertSame(
            "archive/{$site->id}/{$session->id}",
            SessionLayoutResolver::for($session, $site)->prefix(),
        );
    }

    /**
     * The prefix is built from the SITE, not from whoever happens to be asking — a command-line
     * default of 1 for the client id is what sent deep-verify looking in the wrong place.
     *
     * The layout no longer carries a client id at all (it is `mentenanta/{domain}/{date}/…`), so the
     * guarantee is asserted where it now lives: the site's own domain, and nobody else's.
     */
    public function test_the_prefix_is_built_from_the_site_being_backed_up(): void
    {
        $client = Client::factory()->create();
        $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://feaagalati.ro']);
        $session = $this->makeBackupSession($site);

        $this->assertStringStartsWith(
            'mentenanta/feaagalati.ro/',
            SessionLayoutResolver::for($session, $site)->prefix(),
        );
    }

    public function test_a_site_without_a_client_still_resolves(): void
    {
        $site = Site::factory()->create(['client_id' => null, 'url' => 'https://m1-beauty.hu']);
        $session = $this->makeBackupSession($site);

        $this->assertStringStartsWith('mentenanta/m1-beauty.hu/', SessionLayoutResolver::for($session, $site)->prefix());
    }

    public function test_freezing_the_prefix_is_not_recorded_as_activity(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site);
        $updatedBefore = $session->updated_at;

        $this->travel(1)->minute();
        SessionLayoutResolver::for($session, $site);

        $this->assertEquals(
            $updatedBefore?->timestamp,
            $session->fresh()->updated_at?->timestamp,
            'Recording where a session already is is a fact about it, not a change to it.',
        );
    }

    public function test_from_prefix_round_trips_exactly(): void
    {
        $layout = ObjectLayout::fromPrefix('clients/7/sites/32/backups/2');

        $this->assertSame('clients/7/sites/32/backups/2', $layout->prefix());
        $this->assertSame('clients/7/sites/32/backups/2/manifest.json', $layout->manifest());
    }
}
