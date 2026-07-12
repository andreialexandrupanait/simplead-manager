<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchAnalyticsData;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2-48: the Analytics "previous period" comparison was permanently null — the
 * previous window was never fetched, so overview_previous was always absent and
 * every delta resolved to null. The fetch must now request the preceding window
 * and persist it as overview_previous.
 */
class FetchAnalyticsPreviousPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_persists_a_non_null_previous_overview(): void
    {
        // Fresh, usable Google token (double-encrypted like the service expects).
        $google = GoogleConnection::factory()->create([
            'is_active' => true,
            'token_expires_at' => now()->addHour(),
            'access_token' => encrypt('valid-token'),
            'refresh_token' => encrypt('refresh-token'),
        ]);

        $site = Site::factory()->create();

        AnalyticsConnection::create([
            'site_id' => $site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 60,
        ]);

        // Every runReport call (current + previous window) returns the same row.
        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [[
                    'dimensionValues' => [['value' => '20260101']],
                    'metricValues' => [
                        ['value' => '100'], ['value' => '50'], ['value' => '200'],
                        ['value' => '500'], ['value' => '0.4'], ['value' => '60'],
                        ['value' => '150'], ['value' => '0.7'],
                    ],
                ]],
            ], 200),
        ]);

        (new FetchAnalyticsData($site, '28d'))->handle();

        $cache = \App\Models\AnalyticsCache::where('site_id', $site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        $this->assertNotNull($cache);
        $this->assertArrayHasKey('overview_previous', $cache->data);
        // A non-null previous-period value is present, so the delta renders.
        $this->assertSame(100, $cache->data['overview_previous']['total_users']);
    }
}
