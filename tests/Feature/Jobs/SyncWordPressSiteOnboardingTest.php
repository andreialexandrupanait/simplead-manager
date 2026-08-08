<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\ApplySitePresetJob;
use App\Jobs\CreateBackup;
use App\Jobs\RunSecurityScan;
use App\Jobs\SyncWordPressSite;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SPEC §10 step 6 / §13 — the once-only first-connect onboarding: apply the
 * Standard SimpleAD preset, run the reference scan, take the first backup.
 */
class SyncWordPressSiteOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getInfo')->willReturn(['wp_version' => '6.5', 'plugin_version' => '2.19.2']);
        $api->method('getPlugins')->willReturn(['plugins' => []]);
        $api->method('getThemes')->willReturn(['themes' => []]);
        $api->method('getUsers')->willReturn(['users' => []]);
        $api->method('getDbCleanupStats')->willReturn([]);
        $api->method('request')->willReturn(new Response(new Psr7Response(200, [], json_encode([]))));

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_first_connect_applies_preset_runs_reference_scan_and_marks_the_site(): void
    {
        $site = Site::factory()->create(['standard_preset_applied_at' => null]);

        (new SyncWordPressSite($site))->handle();

        Queue::assertPushed(ApplySitePresetJob::class, 1);
        Queue::assertPushed(RunSecurityScan::class, 1);
        $this->assertNotNull($site->fresh()->standard_preset_applied_at);
    }

    public function test_first_connect_takes_a_first_backup_only_when_a_destination_is_configured(): void
    {
        $destination = StorageDestination::factory()->create();
        $site = Site::factory()->create(['standard_preset_applied_at' => null]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
        ]);

        (new SyncWordPressSite($site))->handle();

        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
        $this->assertDatabaseHas('backups', ['site_id' => $site->id, 'trigger' => 'onboarding']);
    }

    public function test_a_later_sync_does_not_repeat_the_onboarding(): void
    {
        $site = Site::factory()->create(['standard_preset_applied_at' => now()]);

        (new SyncWordPressSite($site))->handle();

        Queue::assertNotPushed(ApplySitePresetJob::class);
        Queue::assertNotPushed(RunSecurityScan::class);
        Queue::assertNotPushed(CreateBackup::class);
    }
}
