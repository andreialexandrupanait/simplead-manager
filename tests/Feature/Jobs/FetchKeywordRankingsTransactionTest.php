<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchKeywordRankings;
use App\Models\GoogleConnection;
use App\Models\SearchConsoleConnection;
use App\Models\SeoKeywordRanking;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-65: FetchKeywordRankings deleted today's rankings and then inserted the
 * fresh set non-transactionally, so a failure mid-way wiped the day's data with
 * no replacement — and the swallowed exception made tries=2 dead. The
 * delete+insert must now be atomic and failures must surface.
 */
class FetchKeywordRankingsTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function connectedSite(): Site
    {
        Queue::fake(); // suppress FetchSiteFavicon on Site::created

        $site = Site::factory()->create(['url' => 'https://acme.com']);

        $google = GoogleConnection::factory()->create([
            'is_active' => true,
            'token_expires_at' => now()->addHour(),
            'access_token' => encrypt('access-token'),
        ]);

        SearchConsoleConnection::create([
            'site_id' => $site->id,
            'google_connection_id' => $google->id,
            'property_url' => 'https://acme.com',
            'property_type' => 'url',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        return $site;
    }

    private function fakeGscQueries(array $rows): void
    {
        Http::fake([
            'www.googleapis.com/webmasters/v3/*' => Http::response(['rows' => $rows], 200),
        ]);
    }

    public function test_out_of_range_values_are_clamped_not_crashed(): void
    {
        // Garbage/edge Search-Console data (a stray position=100000, a 100% CTR)
        // must be CLAMPED to the numeric column bounds and stored, not overflow
        // and abort the whole day's fetch. Previously position=100000 threw a
        // numeric-overflow mid-transaction (which also intermittently poisoned
        // the shared test connection); the job now clamps before insert.
        $site = $this->connectedSite();

        $this->fakeGscQueries([
            ['keys' => ['boom'], 'position' => 100000, 'clicks' => 1, 'impressions' => 2, 'ctr' => 5.0],
        ]);

        // No throw — the run completes and stores the clamped row.
        (new FetchKeywordRankings($site))->handle();

        $row = SeoKeywordRanking::where('site_id', $site->id)->where('keyword', 'boom')->first();
        $this->assertNotNull($row, 'the clamped row is stored, not dropped');
        $this->assertSame('9999.99', (string) $row->position, 'position clamped to numeric(6,2) max');
        $this->assertSame('99.9999', (string) $row->ctr, 'ctr clamped to numeric(6,4) max');
    }

    public function test_successful_run_replaces_the_days_rankings(): void
    {
        $site = $this->connectedSite();

        SeoKeywordRanking::create([
            'site_id' => $site->id,
            'keyword' => 'stale keyword',
            'keyword_hash' => md5('stale keyword'),
            'position' => 9.9,
            'clicks' => 1,
            'impressions' => 1,
            'ctr' => 0.01,
            // The job replaces the GSC data-date window (now-3d), not today (C-14)
            'recorded_date' => now()->subDays(3)->format('Y-m-d'),
            'is_tracked' => false,
        ]);

        $this->fakeGscQueries([
            ['keys' => ['fresh keyword'], 'position' => 3.5, 'clicks' => 50, 'impressions' => 500, 'ctr' => 0.1],
        ]);

        (new FetchKeywordRankings($site))->handle();

        $this->assertDatabaseMissing('seo_keyword_rankings', [
            'site_id' => $site->id,
            'keyword' => 'stale keyword',
        ]);
        $this->assertDatabaseHas('seo_keyword_rankings', [
            'site_id' => $site->id,
            'keyword' => 'fresh keyword',
        ]);
    }

    public function test_rows_are_labeled_with_the_gsc_data_date_not_the_fetch_date(): void
    {
        // C-14: the job fetches the final GSC window of now()-3d; rows must
        // carry THAT date. Stamping the fetch date shifted history +3 days.
        $site = $this->connectedSite();

        $this->fakeGscQueries([
            ['keys' => ['dated keyword'], 'position' => 2.0, 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1],
        ]);

        (new FetchKeywordRankings($site))->handle();

        $row = SeoKeywordRanking::where('site_id', $site->id)->where('keyword', 'dated keyword')->first();
        $this->assertNotNull($row);
        $this->assertSame(
            now()->subDays(3)->format('Y-m-d'),
            $row->recorded_date->format('Y-m-d')
        );
    }
}
