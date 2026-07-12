<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchAnalyticsData;
use App\Jobs\FetchSearchConsoleData;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-49: the fetch jobs' uniqueId omitted the date range, so a 7d and a 90d
 * fetch for the same site collided under ShouldBeUnique — one was silently
 * dropped and the UI spinner never resolved. The range must be part of the key.
 */
class FetchGoogleDataUniqueIdTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        Queue::fake(); // suppress FetchSiteFavicon on Site::created

        return Site::factory()->create();
    }

    public function test_analytics_unique_id_differs_by_range(): void
    {
        $site = $this->site();

        $this->assertNotSame(
            (new FetchAnalyticsData($site, '7d'))->uniqueId(),
            (new FetchAnalyticsData($site, '90d'))->uniqueId(),
        );

        $this->assertSame('analytics-'.$site->id.'-7d', (new FetchAnalyticsData($site, '7d'))->uniqueId());
        $this->assertSame('analytics-'.$site->id.'-90d', (new FetchAnalyticsData($site, '90d'))->uniqueId());
    }

    public function test_search_console_unique_id_differs_by_range(): void
    {
        $site = $this->site();

        $this->assertNotSame(
            (new FetchSearchConsoleData($site, '7d'))->uniqueId(),
            (new FetchSearchConsoleData($site, '90d'))->uniqueId(),
        );

        $this->assertSame('search-console-'.$site->id.'-28d', (new FetchSearchConsoleData($site, '28d'))->uniqueId());
    }

    public function test_custom_range_is_disambiguated_by_the_custom_window(): void
    {
        $site = $this->site();

        $this->assertNotSame(
            (new FetchAnalyticsData($site, 'custom', '2026-01-01', '2026-01-31'))->uniqueId(),
            (new FetchAnalyticsData($site, 'custom', '2026-02-01', '2026-02-28'))->uniqueId(),
        );
    }
}
