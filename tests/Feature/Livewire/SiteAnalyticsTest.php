<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\FetchAnalyticsData;
use App\Livewire\Sites\Detail\SiteAnalytics;
use App\Models\AnalyticsCache;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_analytics_tab_without_connection(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function user_can_view_analytics_tab_with_cached_data(): void
    {
        $google = GoogleConnection::factory()->active()->create();

        AnalyticsConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123456789',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        AnalyticsCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => now()->subDays(28)->toDateString(),
            'end_date' => now()->toDateString(),
            'data' => [
                'overview' => [
                    'pageviews' => 1500,
                    'total_users' => 300,
                    'sessions' => 500,
                    'bounce_rate' => 45.0,
                ],
                'users_over_time' => [],
                'traffic_sources' => [],
                'top_pages' => [],
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── refreshData() ────────────────────────────────────────────────

    #[Test]
    public function refresh_data_dispatches_fetch_analytics_job_when_connected(): void
    {
        Queue::fake();

        $google = GoogleConnection::factory()->active()->create();

        AnalyticsConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123456789',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('refreshData');

        Queue::assertPushed(FetchAnalyticsData::class, function (FetchAnalyticsData $job) {
            return $job->site->id === $this->site->id;
        });
    }

    #[Test]
    public function refresh_data_does_not_dispatch_job_when_not_connected(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('refreshData');

        Queue::assertNotPushed(FetchAnalyticsData::class);
    }

    // ─── setDateRange() ───────────────────────────────────────────────

    #[Test]
    public function set_date_range_updates_property(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('setDateRange', '7d');

        $this->assertEquals('7d', $component->get('dateRange'));
    }

    #[Test]
    public function set_date_range_dispatches_job_when_no_cache_exists(): void
    {
        Queue::fake();

        $google = GoogleConnection::factory()->active()->create();

        AnalyticsConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123456789',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('setDateRange', '90d');

        Queue::assertPushed(FetchAnalyticsData::class, function (FetchAnalyticsData $job) {
            return $job->site->id === $this->site->id && $job->dateRange === '90d';
        });
    }

    #[Test]
    public function set_date_range_skips_dispatch_when_fresh_cache_exists(): void
    {
        Queue::fake();

        $google = GoogleConnection::factory()->active()->create();

        AnalyticsConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123456789',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        // Fresh cache that has not expired
        AnalyticsCache::create([
            'site_id' => $this->site->id,
            'date_range' => '7d',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
            'data' => ['overview' => []],
            'fetched_at' => now(),
            'expires_at' => now()->addHour(), // still valid
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('setDateRange', '7d');

        Queue::assertNotPushed(FetchAnalyticsData::class);
    }

    // ─── disconnectAnalytics() ────────────────────────────────────────

    #[Test]
    public function user_can_disconnect_analytics(): void
    {
        $google = GoogleConnection::factory()->active()->create();

        $connection = AnalyticsConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123456789',
            'property_name' => 'Test Property',
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteAnalytics::class, ['site' => $this->site])
            ->call('disconnectAnalytics');

        $this->assertDatabaseMissing('analytics_connections', ['id' => $connection->id]);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_analytics(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteAnalytics::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
