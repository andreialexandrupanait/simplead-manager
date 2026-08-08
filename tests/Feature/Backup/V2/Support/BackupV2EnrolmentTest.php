<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Support\BackupV2Gate;
use App\Models\Site;
use App\Services\Backup\BackupV2Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Who may be backed up, and who decides.
 *
 * The allowlist was the right shape for a pilot: one site, changed by editing the deployment. It is
 * the wrong shape once enrolling a site is an ordinary decision — every new site meant a deploy,
 * which is how retention spent a year switched off behind a variable nobody could reach from the
 * page where the policy is configured. Fleet enrolment moves the decision into the product, without
 * giving up the kill switch.
 *
 * These assertions used to be phrased in terms of engineFor() — which of two engines a site ran.
 * There is one engine now, so the question is the one that was underneath all along: may this site
 * be backed up at all. The guarantees are unchanged, and they are the reason this file still exists
 * rather than having been deleted with the roster.
 */
#[Group('backup-v2')]
class BackupV2EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wildcard_allowlist_admits_every_site(): void
    {
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => ['*']]);
        app(BackupV2Settings::class)->setFleetEnrolmentEnabled(false);

        $this->assertTrue(BackupV2Gate::allowsSite($this->site()->id));
    }

    /**
     * The one lever that stops every backup on the fleet without a deploy or a database write. It is
     * the reason this gate is still here at all now that there is nothing to choose between.
     */
    public function test_the_master_switch_stops_everyone(): void
    {
        config(['backup_v2.enabled' => false, 'backup_v2.site_ids' => ['*']]);

        $this->assertFalse(BackupV2Gate::allowsSite($this->site()->id));
    }

    public function test_fleet_enrolment_opens_the_gate_without_touching_the_deployment(): void
    {
        // The switch has to live in the database: the deploy token this manager holds cannot write
        // environment variables, so an env-only switch would be one nobody in the product can reach.
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => []]);
        app(BackupV2Settings::class)->setFleetEnrolmentEnabled(false);
        $site = $this->site();

        $this->assertFalse(BackupV2Gate::allowsSite($site->id), 'closed until someone opens it');

        app(BackupV2Settings::class)->setFleetEnrolmentEnabled(true);

        $this->assertTrue(BackupV2Gate::allowsSite($site->id));
    }

    public function test_the_master_switch_still_wins_over_fleet_enrolment(): void
    {
        // Whatever the screen says, the deployment can still stop every site at once.
        config(['backup_v2.enabled' => false, 'backup_v2.site_ids' => []]);
        app(BackupV2Settings::class)->setFleetEnrolmentEnabled(true);

        $this->assertFalse(BackupV2Gate::allowsSite($this->site()->id));
    }

    /** An empty allowlist means nobody. It has never meant everybody, and must not start. */
    public function test_an_empty_allowlist_still_means_nobody(): void
    {
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => []]);
        app(BackupV2Settings::class)->setFleetEnrolmentEnabled(false);

        $this->assertFalse(BackupV2Gate::allowsSite($this->site()->id));
    }

    public function test_restore_starts_from_the_deployment_and_is_owned_by_the_screen(): void
    {
        config(['backup_v2.restore_enabled' => false]);
        $settings = app(BackupV2Settings::class);

        $this->assertFalse($settings->restoreEnabled(), 'before anyone has touched it, the deployment decides');

        $settings->setRestoreEnabled(true);

        $this->assertTrue($settings->restoreEnabled());
    }

    private function site(): Site
    {
        return Site::factory()->create();
    }
}
