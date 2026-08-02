<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Backup\BackupV2Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Who runs the new engine, and who decides.
 *
 * The allowlist was the right shape for a pilot: one site, changed by editing the deployment. It is
 * the wrong shape once enrolling a site is an ordinary decision — every new site meant a deploy,
 * which is how retention spent a year switched off behind a variable nobody could reach from the
 * page where the policy is configured. `*` moves the decision to the column the console owns,
 * without giving up either kill switch.
 */
#[Group('backup-v2')]
class BackupV2EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wildcard_allowlist_hands_the_decision_to_the_column(): void
    {
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => ['*']]);

        $onV2 = $this->siteOn(BackupEngine::V2);
        $onV1 = $this->siteOn(BackupEngine::V1);

        $this->assertSame(BackupEngine::V2, BackupV2Gate::engineFor($onV2));
        $this->assertSame(BackupEngine::V1, BackupV2Gate::engineFor($onV1), 'the column still decides, per site');
    }

    public function test_a_concrete_list_still_narrows_the_fleet(): void
    {
        $enrolled = $this->siteOn(BackupEngine::V2);
        $alsoEnrolled = $this->siteOn(BackupEngine::V2);

        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => [(string) $enrolled->id]]);

        $this->assertSame(BackupEngine::V2, BackupV2Gate::engineFor($enrolled));
        $this->assertSame(
            BackupEngine::V1,
            BackupV2Gate::engineFor($alsoEnrolled),
            'a site off the list falls back to the old engine whatever its column says',
        );
    }

    public function test_the_master_switch_still_returns_everyone_to_the_old_engine(): void
    {
        config(['backup_v2.enabled' => false, 'backup_v2.site_ids' => ['*']]);

        $this->assertSame(BackupEngine::V1, BackupV2Gate::engineFor($this->siteOn(BackupEngine::V2)));
    }

    public function test_an_empty_allowlist_still_means_nobody(): void
    {
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => []]);

        $this->assertSame(BackupEngine::V1, BackupV2Gate::engineFor($this->siteOn(BackupEngine::V2)));
    }

    public function test_restore_starts_from_the_deployment_and_is_owned_by_the_screen(): void
    {
        config(['backup_v2.restore_enabled' => false]);
        $settings = app(BackupV2Settings::class);

        $this->assertFalse($settings->restoreEnabled(), 'before anyone has touched it, the deployment decides');

        $settings->setRestoreEnabled(true);
        $this->assertTrue($settings->restoreEnabled());

        // ...and the deployment does not silently win it back. A setting the environment overrides
        // is a setting that lies to whoever changed it.
        config(['backup_v2.restore_enabled' => false]);
        $this->assertTrue(app(BackupV2Settings::class)->restoreEnabled());
    }

    private function siteOn(BackupEngine $engine): Site
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create(['site_id' => $site->id, 'backup_engine' => $engine]);

        return $site->fresh();
    }
}
