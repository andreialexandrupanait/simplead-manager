<?php

namespace Tests\Unit\Services;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Services\SecurityActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecurityActivityService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecurityActivityService::class);
        $this->site = Site::factory()->create();
    }

    #[Test]
    public function ingest_logs_stores_activity_logs(): void
    {
        $count = $this->service->ingestLogs($this->site, [
            [
                'event_type' => 'failed_login',
                'username' => 'admin',
                'ip_address' => '192.168.1.1',
                'occurred_at' => now()->toIso8601String(),
            ],
            [
                'event_type' => 'plugin_update',
                'object_type' => 'plugin',
                'object_name' => 'akismet',
                'action' => 'updated',
            ],
        ]);

        $this->assertEquals(2, $count);
        $this->assertDatabaseCount('security_activity_logs', 2);
    }

    #[Test]
    public function ingest_logs_truncates_user_agent(): void
    {
        $longUserAgent = str_repeat('A', 600);

        $this->service->ingestLogs($this->site, [
            [
                'event_type' => 'login',
                'user_agent' => $longUserAgent,
            ],
        ]);

        $log = SecurityActivityLog::first();
        $this->assertEquals(500, strlen($log->user_agent));
    }

    #[Test]
    public function ingest_logs_limits_to_1000(): void
    {
        $logs = array_fill(0, 1100, [
            'event_type' => 'test',
        ]);

        $count = $this->service->ingestLogs($this->site, $logs);

        $this->assertEquals(1000, $count);
    }

    #[Test]
    public function ingest_logs_returns_zero_for_empty(): void
    {
        $count = $this->service->ingestLogs($this->site, []);
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function get_recent_activity_filters_by_days(): void
    {
        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'recent',
            'occurred_at' => now()->subDays(3),
            'created_at' => now(),
        ]);

        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'old',
            'occurred_at' => now()->subDays(10),
            'created_at' => now(),
        ]);

        $recent = $this->service->getRecentActivity($this->site, 7);

        $this->assertCount(1, $recent);
        $this->assertEquals('recent', $recent->first()->event_type);
    }

    #[Test]
    public function get_failed_login_stats_counts_correctly(): void
    {
        // Create failed logins
        foreach (['192.168.1.1', '192.168.1.1', '192.168.1.2'] as $ip) {
            SecurityActivityLog::create([
                'site_id' => $this->site->id,
                'event_type' => 'failed_login',
                'username' => 'admin',
                'ip_address' => $ip,
                'occurred_at' => now()->subHours(1),
                'created_at' => now(),
            ]);
        }

        $stats = $this->service->getFailedLoginStats($this->site, 7);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['unique_ips']);
        $this->assertEquals(1, $stats['unique_usernames']);
        $this->assertCount(2, $stats['top_ips']);
    }

    #[Test]
    public function prune_old_logs_deletes_old_entries(): void
    {
        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'old',
            'occurred_at' => now()->subDays(100),
            'created_at' => now(),
        ]);

        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'recent',
            'occurred_at' => now()->subDays(5),
            'created_at' => now(),
        ]);

        $deleted = $this->service->pruneOldLogs(90);

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseCount('security_activity_logs', 1);
        $this->assertDatabaseHas('security_activity_logs', ['event_type' => 'recent']);
    }
}
