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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The 5-minute dedup window exists to swallow accidental repeats of one event.
 * Keyed only on event + site + severity, it also swallowed a site's SECOND real
 * outage when it flapped down → up → down inside that window — the reader heard
 * about the first and was never told it happened again.
 */
class NotificationDedupDiscriminatorTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);
    }

    private function notify(Site $site, ?string $discriminator): void
    {
        NotificationService::notifySiteEvent(
            $site,
            'site_down',
            'Down',
            "{$site->name} is down",
            severity: 'critical',
            dedupDiscriminator: $discriminator,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('monitoring.aggregate_alert_storms', false);
    }

    public function test_a_second_incident_on_the_same_site_still_alerts(): void
    {
        Queue::fake();
        $this->channel();
        $site = Site::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->notify($site, 'incident-1');
        $this->notify($site, 'incident-2');

        Queue::assertPushed(SendNotificationJob::class, 2);
    }

    public function test_a_repeat_of_the_same_incident_is_still_collapsed(): void
    {
        Queue::fake();
        $this->channel();
        $site = Site::factory()->create(['user_id' => User::factory()->create()->id]);

        // A retried job re-notifies for the same incident; that is the case the
        // window is for.
        $this->notify($site, 'incident-1');
        $this->notify($site, 'incident-1');

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_callers_without_a_discriminator_keep_the_old_behaviour(): void
    {
        Queue::fake();
        $this->channel();
        $site = Site::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->notify($site, null);
        $this->notify($site, null);

        Queue::assertPushed(SendNotificationJob::class, 1);
    }
}
