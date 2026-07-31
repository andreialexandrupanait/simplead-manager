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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every row on the timeline already knew where it pointed — `activity_logs.url`
 * is populated on roughly four rows in five. It was reachable only through a
 * 16px chevron on the far right, so in practice nobody followed it.
 *
 * The row is the link now. The rows that genuinely have nowhere to go stay
 * inert rather than pointing at a dead href.
 */
class ActivityTimelineLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function event(array $attributes = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'type' => ActivityType::Backup,
            'severity' => ActivitySeverity::Info,
            'title' => 'Something happened',
            'created_at' => now(),
        ], $attributes));
    }

    public function test_a_row_with_an_explicit_url_points_there(): void
    {
        $site = Site::factory()->create();
        $this->event(['site_id' => $site->id, 'url' => route('sites.uptime', $site)]);

        Livewire::test(ActivityTimeline::class)
            ->assertSee(route('sites.uptime', $site), escape: false);
    }

    /**
     * The bare ActivityLogger::log() calls pass no url. Those rows used to be
     * unreachable; the site they belong to is the answer the reader wanted.
     */
    public function test_a_row_without_a_url_falls_back_to_its_site(): void
    {
        $site = Site::factory()->create();
        $event = $this->event(['site_id' => $site->id, 'url' => null]);

        $this->assertSame(route('sites.overview', $site), $event->target_url);

        Livewire::test(ActivityTimeline::class)
            ->assertSee(route('sites.overview', $site), escape: false);
    }

    public function test_a_row_with_neither_stays_inert(): void
    {
        $event = $this->event(['site_id' => null, 'url' => null, 'title' => 'Signed in']);

        $this->assertNull($event->target_url);

        Livewire::test(ActivityTimeline::class)->assertSee('Signed in');
    }

    /**
     * The row's own anchor to the site must stay separately clickable — that is
     * the whole reason the overlay sits underneath rather than wrapping the row.
     */
    public function test_the_site_link_stays_above_the_row_overlay(): void
    {
        $site = Site::factory()->create(['name' => 'clickable.test']);
        $this->event(['site_id' => $site->id, 'url' => route('sites.uptime', $site)]);

        Livewire::test(ActivityTimeline::class)
            ->assertSee('relative z-10', escape: false)
            ->assertSee('clickable.test');
    }
}
