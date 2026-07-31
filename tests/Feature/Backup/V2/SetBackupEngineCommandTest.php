<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\BackupEngine;
use App\Models\BackupConfig;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The roster is a column so a site can move without a redeploy, and so the
 * decision is queryable rather than living in a container's environment.
 *
 * The part worth testing is the refusal to pretend. Setting the column for a
 * site the gate does not permit changes nothing at dispatch time, and hearing
 * that at the moment of the change is far better than discovering it from a
 * backup that ran on the engine you thought you had left.
 */
class SetBackupEngineCommandTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create(['site_id' => $site->id]);

        return $site;
    }

    public function test_it_moves_a_site_to_the_new_engine(): void
    {
        $site = $this->site();
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', [(string) $site->id]);

        $this->artisan('backup:set-engine', ['site' => $site->id, 'engine' => 'v2'])
            ->assertSuccessful();

        $this->assertSame(BackupEngine::V2, $site->backupConfig->fresh()->backup_engine);
    }

    public function test_it_moves_a_site_back(): void
    {
        $site = $this->site();
        $site->backupConfig->update(['backup_engine' => BackupEngine::V2]);

        $this->artisan('backup:set-engine', ['site' => $site->id, 'engine' => 'v1'])
            ->assertSuccessful();

        $this->assertSame(BackupEngine::V1, $site->backupConfig->fresh()->backup_engine);
    }

    public function test_it_says_so_when_the_gate_will_overrule_the_column(): void
    {
        $site = $this->site();
        Config::set('backup_v2.enabled', false);

        $this->artisan('backup:set-engine', ['site' => $site->id, 'engine' => 'v2'])
            ->expectsOutputToContain('will still run v1')
            ->assertSuccessful();
    }

    public function test_an_unknown_engine_is_refused(): void
    {
        $this->artisan('backup:set-engine', ['site' => $this->site()->id, 'engine' => 'v3'])
            ->assertFailed();
    }

    public function test_a_site_without_a_backup_configuration_is_refused(): void
    {
        $site = Site::factory()->create();

        $this->artisan('backup:set-engine', ['site' => $site->id, 'engine' => 'v2'])
            ->assertFailed();
    }
}
