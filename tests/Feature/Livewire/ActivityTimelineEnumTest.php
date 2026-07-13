<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActivitySeverity;
use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Livewire\Activity\ActivityTimeline;
use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-28: activity type/severity are now string-backed enums used in the model
 * cast and the timeline filter, and the "since" cursor is pinned to UTC so
 * filtering is deterministic across timezones.
 */
class ActivityTimelineEnumTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * Every type actually emitted across the codebase must be a valid enum case
     * AND surface in the filter options — previously several (user, database,
     * seo, seo_fix, webhook, incident_response, error_log, dns) were missing.
     */
    public function test_emitted_types_are_valid_enum_cases_and_appear_in_the_filter(): void
    {
        $emitted = [
            'uptime', 'backup', 'update', 'plugin', 'security', 'auth', 'performance',
            'report', 'app_backup', 'retention', 'user', 'database', 'dns', 'error_log',
            'incident_response', 'seo', 'seo_fix', 'webhook', 'connection_error', 'portal',
        ];

        $options = ActivityType::filterOptions();

        foreach ($emitted as $type) {
            $this->assertNotNull(ActivityType::tryFrom($type), "{$type} must be a valid ActivityType case");
            $this->assertArrayHasKey($type, $options, "{$type} must appear in the timeline filter options");
        }
    }

    public function test_type_and_severity_are_cast_to_enums_including_a_previously_missing_type(): void
    {
        $log = ActivityLog::create([
            'type' => 'webhook',
            'severity' => 'warning',
            'title' => 'Incoming webhook',
            'created_at' => now(),
        ]);

        $fresh = $log->fresh();
        $this->assertSame(ActivityType::Webhook, $fresh->type);
        $this->assertSame(ActivitySeverity::Warning, $fresh->severity);
    }

    public function test_unknown_legacy_type_degrades_to_null_instead_of_throwing(): void
    {
        $log = ActivityLog::create([
            'type' => 'legacy_removed_type',
            'severity' => 'info',
            'title' => 'Old row',
            'created_at' => now(),
        ]);

        // Must not throw a ValueError on read.
        $this->assertNull($log->fresh()->type);
    }

    public function test_filtering_by_a_newly_covered_type_works(): void
    {
        $site = Site::factory()->create();
        ActivityLog::create(['site_id' => $site->id, 'type' => 'dns', 'severity' => 'warning', 'title' => 'DNS drift', 'created_at' => now()]);
        ActivityLog::create(['site_id' => $site->id, 'type' => 'backup', 'severity' => 'success', 'title' => 'Backup done', 'created_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(ActivityTimeline::class)
            ->set('filter', 'dns')
            ->assertSee('DNS drift')
            ->assertDontSee('Backup done');
    }

    public function test_since_cursor_is_timezone_stable_and_deterministic(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $site = Site::factory()->create();
        // Inside the 1-week window.
        ActivityLog::create(['site_id' => $site->id, 'type' => 'backup', 'severity' => 'success', 'title' => 'Inside window', 'created_at' => now('UTC')->subDays(3)]);
        // Outside the 1-week window.
        ActivityLog::create(['site_id' => $site->id, 'type' => 'backup', 'severity' => 'success', 'title' => 'Outside window', 'created_at' => now('UTC')->subDays(10)]);

        Livewire::actingAs($this->admin())
            ->test(ActivityTimeline::class)
            ->set('dateRange', 'week')
            ->assertSee('Inside window')
            ->assertDontSee('Outside window');
    }
}
