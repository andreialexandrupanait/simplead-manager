<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention's dry-run exists to produce evidence before anyone enables
 * deletion. It writes that evidence through Log::info, and production runs at
 * LOG_LEVEL=warning — so it has been writing to nowhere for months.
 *
 * This command answers the question directly. It must stay strictly read-only:
 * its whole value is that you can run it on production without thinking twice.
 */
class RetentionReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->destination = StorageDestination::factory()->local()->create(['used_bytes' => 0]);
    }

    private function siteWithPolicy(string $type, int $value, bool $enabled = true): Site
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $this->destination->id,
            'retention_type' => $type,
            'retention_value' => $value,
            'is_enabled' => $enabled,
        ]);

        return $site;
    }

    private function backup(Site $site, string $ago, int $size = 1_000_000): Backup
    {
        return Backup::factory()->completed()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $this->destination->id,
            'file_path' => 'backups/'.fake()->uuid().'.zip',
            'file_size' => $size,
            'created_at' => now()->sub($ago),
            'replicas' => [],
        ]);
    }

    public function test_it_reports_what_would_be_deleted_without_deleting_it(): void
    {
        $site = $this->siteWithPolicy('count', 1);
        $this->backup($site, '10 days');
        $this->backup($site, '1 day');

        $this->artisan('backup:retention-report', ['--json' => true])->assertSuccessful();

        // The whole point: nothing moved.
        $this->assertSame(2, Backup::where('site_id', $site->id)->count());
    }

    public function test_the_report_is_unaffected_by_the_dry_run_flag(): void
    {
        // Even with deletion armed, the report must not delete. It answers a
        // question; it does not enact a policy.
        app(\App\Services\Backup\BackupRetentionSettings::class)->setDeletesEnabled(true);

        $site = $this->siteWithPolicy('count', 1);
        $this->backup($site, '10 days');
        $this->backup($site, '1 day');

        $this->artisan('backup:retention-report')->assertSuccessful();

        $this->assertSame(2, Backup::where('site_id', $site->id)->count());
    }

    /**
     * The report must show the guard, not hide behind it — a site that would
     * have lost everything is exactly what an operator needs to see before
     * arming retention.
     */
    public function test_it_flags_a_site_that_would_have_lost_every_restore_point(): void
    {
        $site = $this->siteWithPolicy('days', 30);
        $this->backup($site, '90 days');
        $this->backup($site, '51 days');

        $this->artisan('backup:retention-report')
            ->expectsOutputToContain('KEPT LAST')
            ->assertSuccessful();
    }

    public function test_a_site_within_policy_shows_nothing_to_delete(): void
    {
        $site = $this->siteWithPolicy('days', 30);
        $this->backup($site, '2 days');

        $this->artisan('backup:retention-report', ['--site' => $site->id, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(1, Backup::where('site_id', $site->id)->count());
    }

    public function test_it_says_so_when_nothing_matches(): void
    {
        $this->artisan('backup:retention-report', ['--site' => 999999])
            ->expectsOutputToContain('No backup configuration matched')
            ->assertSuccessful();
    }
}
