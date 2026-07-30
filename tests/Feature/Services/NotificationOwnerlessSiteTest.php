<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\NotificationEventPreference;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `sites.user_id` is nullable, and several production sites have no owner.
 * isEventEnabledForChannel() used to type-hint `int $userId`, so those sites
 * threw a TypeError before any channel was reached — every uptime alert for an
 * owner-less site was lost (8 occurrences on 2026-07-30 alone).
 *
 * With no user there is no preference to consult, so the default (enabled)
 * applies — the same answer a user without a preference row gets.
 */
class NotificationOwnerlessSiteTest extends TestCase
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

    public function test_site_without_owner_still_notifies_the_default_channel(): void
    {
        Queue::fake();
        $this->defaultChannel();
        $site = Site::factory()->create(['user_id' => null]);

        NotificationService::notifySiteEvent($site, 'backup_failed', 'Failed', 'msg', severity: 'warning');

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_slim_uptime_alert_for_owner_less_site_does_not_throw(): void
    {
        Queue::fake();
        $this->defaultChannel();
        $site = Site::factory()->create(['user_id' => null]);

        // The exact production path: NotifyIncident -> notifySiteEventSlim(site_recovered).
        // site_down/site_recovered are coalesced by alert-storm aggregation, so the
        // assertion here is that the call completes — the TypeError used to abort it
        // before the buffer was ever reached.
        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'site_recovered',
            summary: 'recovered after 4m',
            severity: 'success',
        );

        $this->assertTrue(true);
    }

    public function test_an_explicit_disabled_preference_still_suppresses_for_an_owned_site(): void
    {
        Queue::fake();
        $channel = $this->defaultChannel();
        $user = User::factory()->create();
        $site = Site::factory()->create(['user_id' => $user->id]);

        NotificationEventPreference::create([
            'user_id' => $user->id,
            'notification_channel_id' => $channel->id,
            'event' => 'backup_failed',
            'enabled' => false,
        ]);

        NotificationService::notifySiteEvent($site, 'backup_failed', 'Failed', 'msg', severity: 'warning');

        // The null-guard must not weaken the opt-out for sites that DO have an owner.
        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
