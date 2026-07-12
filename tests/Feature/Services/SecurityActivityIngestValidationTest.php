<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Services\SecurityActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-11: remote audit fields flow into typed Postgres columns (inet, varchar,
 * timestamp). A single malformed value (bad IP, over-length string, garbage or
 * future date) previously failed the whole bulk insert, so the watermark never
 * advanced and the site's ingestion wedged forever. Ingestion must now validate,
 * clamp and stay row-tolerant.
 */
class SecurityActivityIngestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(): SecurityActivityService
    {
        return app(SecurityActivityService::class);
    }

    public function test_invalid_ip_is_nulled_so_the_inet_insert_never_fails(): void
    {
        $site = Site::factory()->create();

        // 'not-an-ip' would abort a bulk insert into the inet column outright.
        $count = $this->service()->ingestLogs($site, [
            ['event_type' => 'user_login', 'ip_address' => '203.0.113.7', 'occurred_at' => '2026-07-05 10:00:00'],
            ['event_type' => 'user_login', 'ip_address' => 'not-an-ip', 'occurred_at' => '2026-07-05 10:00:01'],
        ]);

        $this->assertSame(2, $count, 'Both rows must insert; the bad IP is nulled, not fatal.');
        $this->assertDatabaseHas('security_activity_logs', ['site_id' => $site->id, 'ip_address' => '203.0.113.7']);
        $this->assertDatabaseHas('security_activity_logs', ['site_id' => $site->id, 'ip_address' => null]);
    }

    public function test_overlong_event_type_is_truncated_to_column_width(): void
    {
        $site = Site::factory()->create();

        $this->service()->ingestLogs($site, [
            ['event_type' => str_repeat('x', 200), 'occurred_at' => '2026-07-05 10:00:00'],
        ]);

        $row = SecurityActivityLog::where('site_id', $site->id)->firstOrFail();
        $this->assertLessThanOrEqual(50, strlen($row->event_type));
    }

    public function test_future_dated_occurred_at_is_clamped_to_now(): void
    {
        $site = Site::factory()->create();

        $this->service()->ingestLogs($site, [
            ['event_type' => 'user_login', 'occurred_at' => now()->addYears(50)->toIso8601String()],
        ]);

        $row = SecurityActivityLog::where('site_id', $site->id)->firstOrFail();
        $this->assertTrue(
            $row->occurred_at->lessThanOrEqualTo(now()->addMinutes(6)),
            'A future-dated row must not jump the watermark past all real events.',
        );
    }

    public function test_unparseable_occurred_at_still_inserts_the_row(): void
    {
        $site = Site::factory()->create();

        $count = $this->service()->ingestLogs($site, [
            ['event_type' => 'user_login', 'occurred_at' => 'total garbage'],
        ]);

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('security_activity_logs', 1);
    }

    public function test_latest_cursor_returns_naive_utc_max(): void
    {
        $cursor = $this->service()->latestCursor([
            ['occurred_at' => '2026-07-05 10:00:00'],
            ['occurred_at' => '2026-07-05 12:30:00'],
            ['occurred_at' => '2026-07-05 09:00:00'],
        ]);

        $this->assertSame('2026-07-05 12:30:00', $cursor);
    }
}
