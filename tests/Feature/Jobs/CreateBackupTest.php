<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\StorageDestination;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateBackupTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);
    }

    /**
     * Bind a mock WordPressApiServiceFactory that returns a mock API.
     *
     * The default mock stubs the minimal methods called before the first real
     * I/O attempt (capability refresh + chunked-download probe), then throws on
     * streamDownload so the job fails quickly without network access.
     */
    private function bindMockFactory(?WordPressApiServiceInterface $mockApi = null): WordPressApiServiceInterface
    {
        if ($mockApi === null) {
            $mockApi = Mockery::mock(WordPressApiServiceInterface::class);
            $mockApi->shouldReceive('setBackupMode')->andReturnNull();
            $mockApi->shouldReceive('resetThrottle')->andReturnNull();
            $mockApi->shouldReceive('getBackupCapabilities')->andReturn(null);
            // /backup/prepare probe returns 404 → chunked download not supported
            $mockApi->shouldReceive('request')->andReturn(
                new ClientResponse(new GuzzleResponse(404))
            );
            // Legacy streamDownload throws to trigger handleFailure
            $mockApi->shouldReceive('streamDownload')->andThrow(new \RuntimeException('No WP site'));
        }

        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockApi);
        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);

        return $mockApi;
    }

    #[Test]
    public function backup_record_created_during_handle(): void
    {
        StorageDestination::factory()->create(['is_default' => true, 'is_active' => true]);
        $this->bindMockFactory();

        try {
            (new CreateBackup($this->site))->handle();
        } catch (\Throwable) {
            // The job fails after creating the Backup record — expected in tests.
        }

        // The backup record was created by prepare(), then handleFailure() updated it to failed.
        $backup = Backup::where('site_id', $this->site->id)->first();
        $this->assertNotNull($backup, 'A Backup record should have been created by prepare()');
        $this->assertEquals(BackupStatus::Failed, $backup->status);
        $this->assertEquals('full', $backup->type);
    }

    #[Test]
    public function backup_fails_without_storage_destination(): void
    {
        // Ensure no storage destinations exist so resolveStorageDestination() returns null.
        StorageDestination::query()->delete();
        $this->bindMockFactory();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('No storage destination available');

        (new CreateBackup($this->site))->handle();
    }

    #[Test]
    public function backup_cancelled_by_user_stops_job(): void
    {
        $destination = StorageDestination::factory()->create(['is_default' => true, 'is_active' => true]);

        $backup = Backup::factory()->for($this->site)->for($destination)->create([
            'status' => BackupStatus::Cancelled,
            'started_at' => now(),
        ]);

        $this->bindMockFactory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup cancelled by user');

        (new CreateBackup(
            site: $this->site,
            storageDestinationId: $destination->id,
            backupId: $backup->id,
        ))->handle();
    }

    #[Test]
    public function backup_failure_updates_status_to_failed(): void
    {
        StorageDestination::factory()->create(['is_default' => true, 'is_active' => true]);

        $mockApi = Mockery::mock(WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('setBackupMode')->andReturnNull();
        $mockApi->shouldReceive('resetThrottle')->andReturnNull();
        $mockApi->shouldReceive('getBackupCapabilities')->andReturn(null);
        $mockApi->shouldReceive('request')->andReturn(
            new ClientResponse(new GuzzleResponse(404))
        );
        $mockApi->shouldReceive('streamDownload')->andThrow(new \RuntimeException('Connection refused'));

        $this->bindMockFactory($mockApi);

        try {
            (new CreateBackup($this->site))->handle();
        } catch (\Throwable) {
            // Expected
        }

        $backup = Backup::where('site_id', $this->site->id)->first();

        $this->assertNotNull($backup, 'A Backup record should have been created before the failure');
        $this->assertEquals(BackupStatus::Failed, $backup->status);
    }

    #[Test]
    public function backup_dispatched_on_backups_queue(): void
    {
        Queue::fake();

        StorageDestination::factory()->create(['is_default' => true, 'is_active' => true]);

        CreateBackup::dispatch($this->site);

        Queue::assertPushedOn('backups', CreateBackup::class);
    }
}
