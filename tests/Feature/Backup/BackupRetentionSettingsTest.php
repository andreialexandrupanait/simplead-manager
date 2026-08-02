<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Livewire\Settings\DataRetentionSettings;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Backup\BackupRetentionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Whether old backups are deleted was an environment variable, and that is exactly why it was never
 * switched on: turning it on meant editing the deployment and redeploying. So retention ran every
 * night, worked out what to remove, wrote it to the log, and removed nothing — while a terabyte
 * accumulated behind a switch nobody could reach from the screen where the policy is set.
 *
 * It is a setting now. The tests below pin the two things that make it trustworthy: it is off until
 * somebody says otherwise, and the deployment can still stop deletions but can never start them.
 */
class BackupRetentionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_is_off_until_somebody_turns_it_on(): void
    {
        $settings = app(BackupRetentionSettings::class);

        $this->assertFalse(
            $settings->deletesEnabled(),
            'a system that starts deleting because it was deployed is worse than one that reports first',
        );

        $settings->setDeletesEnabled(true);
        $this->assertTrue(app(BackupRetentionSettings::class)->deletesEnabled());
    }

    public function test_the_deployment_can_stop_deletions_but_never_start_them(): void
    {
        $settings = app(BackupRetentionSettings::class);
        $settings->setDeletesEnabled(true);
        $this->assertTrue($settings->deletesEnabled());

        // The emergency catch: force dry-run regardless of what the screen says.
        config(['backups.retention_dry_run' => true]);

        $this->assertFalse(
            app(BackupRetentionSettings::class)->deletesEnabled(),
            'the env flag must be able to halt deletions without a UI round trip',
        );
    }

    public function test_the_count_is_written_onto_every_site_schedule(): void
    {
        // Three sites, three different policies — the state the fleet was actually in, which is why
        // one site had 187 restore points and another 12.
        $configs = collect([
            ['retention_type' => 'days', 'retention_value' => 30],
            ['retention_type' => 'count', 'retention_value' => 10],
            ['retention_type' => 'count', 'retention_value' => 7],
        ])->map(fn (array $policy) => BackupConfig::factory()->create(array_merge(
            ['site_id' => Site::factory()->create()->id],
            $policy,
        )));

        $changed = app(BackupRetentionSettings::class)->setKeepPerSite(30);

        $this->assertSame(3, $changed, 'every schedule that disagreed was brought into line');

        foreach ($configs as $config) {
            $config->refresh();
            $this->assertSame('count', $config->retention_type);
            $this->assertSame(30, (int) $config->retention_value);
        }

        // The setting and the schedules must not be able to drift apart — a screen that says 30
        // while a site keeps 7 is worse than no screen.
        $this->assertSame(30, app(BackupRetentionSettings::class)->keepPerSite());
    }

    public function test_the_count_is_clamped_to_something_survivable(): void
    {
        $settings = app(BackupRetentionSettings::class);

        $settings->setKeepPerSite(0);
        $this->assertSame(
            BackupRetentionSettings::MIN_KEEP,
            $settings->keepPerSite(),
            'zero restore points is not a retention policy, it is data loss on a schedule',
        );

        $settings->setKeepPerSite(100000);
        $this->assertSame(BackupRetentionSettings::MAX_KEEP, $settings->keepPerSite());
    }

    public function test_the_screen_saves_both_the_switch_and_the_count(): void
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'retention_type' => 'days',
            'retention_value' => 30,
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(DataRetentionSettings::class)
            ->assertSet('backupDeletesEnabled', false)
            ->set('backupKeepPerSite', 45)
            ->call('saveBackupRetention')
            ->set('backupDeletesEnabled', true);

        $settings = app(BackupRetentionSettings::class);
        $this->assertSame(45, $settings->keepPerSite());
        $this->assertTrue($settings->deletesEnabled(), 'the toggle persists on its own, without a save');
    }

    private function adminUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'admin']);
    }
}
