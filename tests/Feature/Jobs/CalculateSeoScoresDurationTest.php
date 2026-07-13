<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CalculateSeoScores;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\SeoAudit\AuditDiffService;
use App\Services\SeoAudit\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P3-20: Carbon 3 returns signed diffs, so now()->diffInSeconds($created_at)
 * produced a NEGATIVE scan_duration. It must never be negative.
 */
class CalculateSeoScoresDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scan_duration_is_never_negative(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0));

        $site = Site::factory()->create();
        $audit = SeoAudit::create(['site_id' => $site->id, 'status' => 'pending']);
        // The audit started two minutes ago.
        $audit->created_at = Carbon::getTestNow()->copy()->subSeconds(120);
        $audit->save();

        (new CalculateSeoScores($site, $audit))->handle(new ScoringService, new AuditDiffService);

        $audit->refresh();
        $this->assertGreaterThanOrEqual(0, $audit->scan_duration);
        $this->assertSame(120, $audit->scan_duration);
    }
}
