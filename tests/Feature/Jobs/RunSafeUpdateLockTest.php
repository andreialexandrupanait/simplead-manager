<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunSafeUpdate;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RunSafeUpdateLockTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private SafeUpdate $safeUpdate;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        $this->site = Site::factory()->create();
        $this->safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
        ]);
    }

    public function test_safe_update_aborts_and_does_not_run_while_a_restore_holds_the_lock(): void
    {
        // P0-07: a safe update must never run concurrently with a restore.
        SiteOperationLock::acquire(
            $this->site->id,
            SiteOperationLock::OPERATION_RESTORE,
            'backup:123',
        );

        // The service must never be invoked when the site is busy.
        $service = $this->createMock(SafeUpdateService::class);
        $service->expects($this->never())->method('runSafeUpdate');

        $job = new RunSafeUpdate($this->safeUpdate);

        try {
            $job->handle($service);
            $this->fail('Expected RunSafeUpdate to abort when the site lock is held.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('busy with restore', $e->getMessage());
        }

        // The restore's lock must be untouched.
        $holder = SiteOperationLock::current($this->site->id);
        $this->assertNotNull($holder);
        $this->assertSame(SiteOperationLock::OPERATION_RESTORE, $holder['operation']);
    }

    public function test_safe_update_holds_lock_during_run_and_passes_token_then_releases(): void
    {
        $capturedToken = null;

        $service = $this->createMock(SafeUpdateService::class);
        $service->expects($this->once())
            ->method('runSafeUpdate')
            ->willReturnCallback(function ($safeUpdate, $userId, $token) use (&$capturedToken) {
                // While the service runs, the SAFE_UPDATE lock is held and a
                // non-null token was threaded down for the nested backup.
                $capturedToken = $token;
                $holder = SiteOperationLock::current($this->site->id);
                $this->assertNotNull($holder);
                $this->assertSame(SiteOperationLock::OPERATION_SAFE_UPDATE, $holder['operation']);
            });

        $job = new RunSafeUpdate($this->safeUpdate, 7);
        $job->handle($service);

        $this->assertIsString($capturedToken);
        $this->assertNotSame('', $capturedToken);
        // Lock released after the run.
        $this->assertFalse(SiteOperationLock::isHeld($this->site->id));
    }
}
