<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunSafeUpdate;
use App\Models\SafeUpdate;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class RunSafeUpdateTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_job_delegates_to_safe_update_service(): void
    {
        $site = $this->createSite();

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
        ]);

        $mock = $this->mock(SafeUpdateService::class);
        $mock->shouldReceive('runSafeUpdate')
            ->once()
            ->with(\Mockery::on(fn ($arg) => $arg->id === $safeUpdate->id));

        $job = new RunSafeUpdate($safeUpdate);
        $job->handle($mock);
    }
}
