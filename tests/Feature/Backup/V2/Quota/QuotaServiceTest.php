<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Quota;

use App\Backup\V2\Quota\QuotaExceededException;
use App\Backup\V2\Quota\QuotaService;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function service(): QuotaService
    {
        return app(QuotaService::class);
    }

    public function test_under_quota_is_allowed(): void
    {
        config(['backup_v2.quota.enforce' => true]);
        $d = StorageDestination::factory()->create(['used_bytes' => 1_000_000, 'quota_bytes' => 10_000_000]);

        $this->service()->assertWithinQuota($d, 500_000);

        $this->assertFalse($this->service()->wouldExceed($d, 500_000));
    }

    public function test_over_quota_is_blocked_when_enforced(): void
    {
        config(['backup_v2.quota.enforce' => true]);
        $d = StorageDestination::factory()->create(['name' => 'Full Bucket', 'used_bytes' => 10_000_000, 'quota_bytes' => 10_000_000]);

        $this->expectException(QuotaExceededException::class);
        $this->service()->assertWithinQuota($d, 1);
    }

    public function test_over_quota_is_not_blocked_when_enforcement_off(): void
    {
        config(['backup_v2.quota.enforce' => false]);
        $d = StorageDestination::factory()->create(['used_bytes' => 20_000_000, 'quota_bytes' => 10_000_000]);

        // No exception — reporting only.
        $this->service()->assertWithinQuota($d, 5_000_000);
        $this->assertTrue(true);
    }

    public function test_unbounded_destination_never_exceeds(): void
    {
        config(['backup_v2.quota.enforce' => true]);
        $d = StorageDestination::factory()->create(['used_bytes' => 999_000_000, 'quota_bytes' => null]);

        $this->assertFalse($this->service()->wouldExceed($d, 1_000_000_000));
        $this->assertNull($this->service()->usagePercent($d));
        $this->service()->assertWithinQuota($d, 1_000_000_000);
    }

    public function test_usage_percent_and_remaining(): void
    {
        $d = StorageDestination::factory()->create(['used_bytes' => 7_500_000, 'quota_bytes' => 10_000_000]);

        $this->assertSame(75.0, $this->service()->usagePercent($d));
        $this->assertSame(2_500_000, $this->service()->remainingBytes($d));
    }

    public function test_over_warn_threshold(): void
    {
        config(['backup_v2.quota.warn_threshold_percent' => 90]);
        $under = StorageDestination::factory()->create(['used_bytes' => 8_000_000, 'quota_bytes' => 10_000_000]);
        $over = StorageDestination::factory()->create(['used_bytes' => 9_500_000, 'quota_bytes' => 10_000_000]);

        $this->assertFalse($this->service()->isOverWarnThreshold($under));
        $this->assertTrue($this->service()->isOverWarnThreshold($over));
    }
}
