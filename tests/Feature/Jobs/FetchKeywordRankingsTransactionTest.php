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

    public function test_failed_insert_rolls_back_the_delete_and_surfaces_the_error(): void
    {
        $site = $this->connectedSite();

        // A pre-existing "good" placement for today that must survive a failed run.
        SeoKeywordRanking::create([
            'site_id' => $site->id,
            'keyword' => 'existing keyword',
            'keyword_hash' => md5('existing keyword'),
            'position' => 4.2,
            'clicks' => 10,
            'impressions' => 100,
            'ctr' => 0.1,
            'recorded_date' => now()->format('Y-m-d'),
            'is_tracked' => true,
        ]);

        // position 100000 overflows numeric(6,2) → the insert throws mid-run.
        $this->fakeGscQueries([
            ['keys' => ['boom'], 'position' => 100000, 'clicks' => 1, 'impressions' => 2, 'ctr' => 0.01],
        ]);

        $threw = false;
        try {
            (new FetchKeywordRankings($site))->handle();
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The job must rethrow so tries=2 engages and the failure is visible.');

        // The delete was rolled back with the failed insert: the day's data is intact.
        $this->assertDatabaseHas('seo_keyword_rankings', [
            'site_id' => $site->id,
            'keyword' => 'existing keyword',
            'recorded_date' => now()->format('Y-m-d'),
        ]);
        $this->assertDatabaseMissing('seo_keyword_rankings', [
            'site_id' => $site->id,
            'keyword' => 'boom',
        ]);
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
            'recorded_date' => now()->format('Y-m-d'),
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
}
