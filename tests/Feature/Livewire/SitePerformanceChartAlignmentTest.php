<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SitePerformance;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-19: the score-history chart built each device's series as an independent
 * positional array while the x-axis labels were a deduped union of both devices'
 * dates — so a mobile-only day and a desktop-only day shifted the two lines out
 * of alignment. Points must now pair to the SAME date bucket.
 */
class SitePerformanceChartAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mobile_and_desktop_points_align_by_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0));

        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->id]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        // Mobile only on Jul 10; desktop only on Jul 11.
        PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'mobile',
            'status' => 'completed',
            'performance_score' => 50,
            'tested_at' => Carbon::create(2026, 7, 10, 4, 0, 0),
        ]);
        PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'desktop',
            'status' => 'completed',
            'performance_score' => 80,
            'tested_at' => Carbon::create(2026, 7, 11, 4, 0, 0),
        ]);

        $history = Livewire::actingAs($user)
            ->test(SitePerformance::class, ['site' => $site])
            ->instance()
            ->scoreHistory();

        $this->assertSame(['Jul 10', 'Jul 11'], $history['labels']);

        $mobile = collect($history['datasets'])->firstWhere('label', 'Mobile');
        $desktop = collect($history['datasets'])->firstWhere('label', 'Desktop');

        // Mobile score sits under Jul 10, desktop under Jul 11 — the gap is null.
        $this->assertSame([50, null], $mobile['data']);
        $this->assertSame([null, 80], $desktop['data']);
    }
}
