<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunSafeUpdate;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunSafeUpdateTest extends TestCase
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

    #[Test]
    public function delegates_to_safe_update_service(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        $mockService = Mockery::mock(SafeUpdateService::class);
        $mockService->shouldReceive('runSafeUpdate')
            ->once()
            ->with(Mockery::on(fn ($su) => $su->id === $safeUpdate->id), null);

        $job = new RunSafeUpdate($safeUpdate);
        $job->handle($mockService);
    }

    #[Test]
    public function passes_user_id_to_service(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        $mockService = Mockery::mock(SafeUpdateService::class);
        $mockService->shouldReceive('runSafeUpdate')
            ->once()
            ->with(Mockery::on(fn ($su) => $su->id === $safeUpdate->id), 42);

        $job = new RunSafeUpdate($safeUpdate, userId: 42);
        $job->handle($mockService);
    }

    #[Test]
    public function failure_updates_safe_update_to_failed(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create([
            'status' => 'pending',
        ]);

        $job = new RunSafeUpdate($safeUpdate);
        // NotificationService::notifySiteEvent is static — it will run but
        // with array mail driver it won't send real emails.
        $job->failed(new \RuntimeException('Plugin incompatible'));

        $safeUpdate->refresh();
        $this->assertEquals('failed', $safeUpdate->status);
        $this->assertEquals('Plugin incompatible', $safeUpdate->error_message);
        $this->assertNotNull($safeUpdate->completed_at);
    }

    #[Test]
    public function failure_with_null_exception_sets_failed_status(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create([
            'status' => 'updating',
        ]);

        $job = new RunSafeUpdate($safeUpdate);
        $job->failed(null);

        $safeUpdate->refresh();
        $this->assertEquals('failed', $safeUpdate->status);
        $this->assertNull($safeUpdate->error_message);
        $this->assertNotNull($safeUpdate->completed_at);
    }

    #[Test]
    public function unique_id_uses_safe_update_id(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        $job = new RunSafeUpdate($safeUpdate);

        $this->assertSame('safe-update-'.$safeUpdate->id, $job->uniqueId());
    }

    #[Test]
    public function has_correct_job_properties(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        $job = new RunSafeUpdate($safeUpdate);

        $this->assertSame(1, $job->tries);
        $this->assertSame(600, $job->timeout);
    }

    #[Test]
    public function dispatches_as_queued_job(): void
    {
        Queue::fake();

        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        RunSafeUpdate::dispatch($safeUpdate);

        Queue::assertPushed(RunSafeUpdate::class, function ($job) use ($safeUpdate) {
            return $job->safeUpdate->id === $safeUpdate->id;
        });
    }

    #[Test]
    public function service_exception_propagates(): void
    {
        $safeUpdate = SafeUpdate::factory()->for($this->site)->create();

        $mockService = Mockery::mock(SafeUpdateService::class);
        $mockService->shouldReceive('runSafeUpdate')
            ->andThrow(new \RuntimeException('Update service crashed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Update service crashed');

        $job = new RunSafeUpdate($safeUpdate);
        $job->handle($mockService);
    }
}
