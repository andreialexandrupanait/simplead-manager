<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\ProcessNotificationBatch;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * C-11: when many sites go down (or recover) at once — a shared cause — the
 * platform must send ONE aggregated message per channel instead of one per site.
 * Aggregation routes site_down/site_recovered through the batch buffer, which
 * ProcessNotificationBatch coalesces.
 */
class AlertStormAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real Redis in tests — clear any buffer left by other tests.
        Redis::del('notification_buffer');
        Redis::del('notification_buffer:processing');
    }

    private function channel(): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null, // all events
        ]);
    }

    private function downSites(int $count, string $event): void
    {
        foreach (range(1, $count) as $i) {
            $user = User::factory()->create();
            $site = Site::factory()->create(['user_id' => $user->id]);
            NotificationService::notifySiteEvent(
                $site,
                $event,
                $event === 'site_down' ? 'Down' : 'Recovered',
                "{$site->name} {$event}",
                severity: $event === 'site_down' ? 'critical' : 'success',
            );
        }
    }

    public function test_a_storm_of_downs_coalesces_into_one_message_per_channel(): void
    {
        config()->set('monitoring.aggregate_alert_storms', true);
        Queue::fake();
        $this->channel();

        $this->downSites(20, 'site_down');

        // Nothing sent yet — all 20 buffered, not one-per-site.
        Queue::assertNotPushed(SendNotificationJob::class);

        (new ProcessNotificationBatch)->handle();

        // Exactly one grouped send for the channel — not 20.
        Queue::assertPushed(SendNotificationJob::class, 1);
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->event === 'site_down' && str_starts_with($job->title, '20x'),
        );
    }

    public function test_recovery_storm_coalesces_separately_from_downs(): void
    {
        config()->set('monitoring.aggregate_alert_storms', true);
        Queue::fake();
        $this->channel();

        $this->downSites(5, 'site_down');
        $this->downSites(5, 'site_recovered');

        (new ProcessNotificationBatch)->handle();

        // One grouped down message + one grouped recovery message = 2 total.
        Queue::assertPushed(SendNotificationJob::class, 2);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->event === 'site_down');
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->event === 'site_recovered');
    }

    public function test_aggregation_off_dispatches_one_message_per_site(): void
    {
        config()->set('monitoring.aggregate_alert_storms', false);
        Queue::fake();
        $this->channel();

        $this->downSites(3, 'site_down');

        // Immediate per-site delivery (legacy behavior) — no buffering.
        Queue::assertPushed(SendNotificationJob::class, 3);
    }
}
