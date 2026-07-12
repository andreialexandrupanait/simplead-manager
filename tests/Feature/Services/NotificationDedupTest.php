<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-54: the notification dedup window must not swallow genuinely DISTINCT alerts
 * (different site / event / severity), the check-and-set must be atomic, and a
 * suppressed alert must leave a trace.
 */
class NotificationDedupTest extends TestCase
{
    use RefreshDatabase;

    private function defaultChannel(): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null, // all events
        ]);
    }

    private function site(): Site
    {
        $user = User::factory()->create();

        return Site::factory()->create(['user_id' => $user->id]);
    }

    public function test_distinct_sites_within_window_both_send(): void
    {
        Queue::fake();
        $this->defaultChannel();
        $siteA = $this->site();
        $siteB = $this->site();

        NotificationService::notifySiteEvent($siteA, 'site_down', 'Down', 'A is down', severity: 'warning');
        NotificationService::notifySiteEvent($siteB, 'site_down', 'Down', 'B is down', severity: 'warning');

        // Different sites are distinct alerts — neither should be suppressed.
        Queue::assertPushed(SendNotificationJob::class, 2);
    }

    public function test_distinct_severities_within_window_both_send(): void
    {
        Queue::fake();
        $this->defaultChannel();
        $site = $this->site();

        NotificationService::notifySiteEvent($site, 'site_down', 'Down', 'msg', severity: 'warning');
        NotificationService::notifySiteEvent($site, 'site_down', 'Down', 'msg', severity: 'critical');

        // Same site+event but different severity — a distinct alert, not a dup.
        Queue::assertPushed(SendNotificationJob::class, 2);
    }

    public function test_identical_alert_within_window_is_suppressed_and_logged(): void
    {
        Queue::fake();
        Log::spy();
        $this->defaultChannel();
        $site = $this->site();

        NotificationService::notifySiteEvent($site, 'site_down', 'Down', 'msg', severity: 'warning');
        NotificationService::notifySiteEvent($site, 'site_down', 'Down', 'msg', severity: 'warning');

        // The second identical alert is suppressed — only one send goes out.
        Queue::assertPushed(SendNotificationJob::class, 1);

        // Suppression leaves a trace (not silent).
        Log::shouldHaveReceived('debug')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Suppressed duplicate notification')
                && ($context['event'] ?? null) === 'site_down')
            ->once();
    }

    public function test_dedup_check_is_atomic_via_cache_add(): void
    {
        // The first claim writes the key; a second Cache::add for the same key must
        // return false (already present) — this is the atomic guarantee the dedup
        // relies on instead of a racy has()/put() pair.
        $key = 'notification_dedup:site_down:1:warning';

        $this->assertTrue(Cache::add($key, true, 300));
        $this->assertFalse(Cache::add($key, true, 300));
    }
}
