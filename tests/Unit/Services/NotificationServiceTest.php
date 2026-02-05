<?php

namespace Tests\Unit\Services;

use App\Jobs\SendNotificationJob;
use App\Models\AppSetting;
use App\Models\NotificationChannel;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_dispatches_job_for_each_default_active_channel(): void
    {
        Bus::fake();
        $site = $this->createSite();

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => null, // subscribes to all events
        ]);

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'slack',
            'event_subscriptions' => null,
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
        );

        Bus::assertDispatched(SendNotificationJob::class, 2);
    }

    public function test_skips_inactive_channels(): void
    {
        Bus::fake();
        $site = $this->createSite();

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => false,
            'type' => 'email',
            'event_subscriptions' => null,
        ]);

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'slack',
            'event_subscriptions' => null,
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
        );

        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_skips_channels_not_subscribed_to_event(): void
    {
        Bus::fake();
        $site = $this->createSite();

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => ['backup_completed'], // not subscribed to site_down
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
        );

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_uses_specific_channel_ids_when_provided(): void
    {
        Bus::fake();
        $site = $this->createSite();

        $specificChannel = NotificationChannel::factory()->create([
            'is_default' => false,
            'is_active' => true,
            'type' => 'webhook',
            'event_subscriptions' => null,
        ]);

        // This default channel should be ignored when channelIds are specified
        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => null,
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
            channelIds: [$specificChannel->id],
        );

        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_quiet_hours_skip_non_critical_notifications(): void
    {
        Bus::fake();
        $site = $this->createSite();

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => null,
        ]);

        // Enable quiet hours for current time
        AppSetting::create([
            'key' => 'quiet_hours_enabled',
            'value' => '1',
            'group' => 'notifications',
            'type' => 'boolean',
        ]);

        AppSetting::create([
            'key' => 'quiet_hours_start',
            'value' => '00:00',
            'group' => 'notifications',
            'type' => 'string',
        ]);

        AppSetting::create([
            'key' => 'quiet_hours_end',
            'value' => '23:59',
            'group' => 'notifications',
            'type' => 'string',
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'backup_completed',
            title: 'Backup Done',
            message: 'Backup completed.',
            severity: 'info',
        );

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_critical_notifications_bypass_quiet_hours(): void
    {
        Bus::fake();
        $site = $this->createSite();

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => null,
        ]);

        // Enable quiet hours for current time
        AppSetting::create([
            'key' => 'quiet_hours_enabled',
            'value' => '1',
            'group' => 'notifications',
            'type' => 'boolean',
        ]);

        AppSetting::create([
            'key' => 'quiet_hours_start',
            'value' => '00:00',
            'group' => 'notifications',
            'type' => 'string',
        ]);

        AppSetting::create([
            'key' => 'quiet_hours_end',
            'value' => '23:59',
            'group' => 'notifications',
            'type' => 'string',
        ]);

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
        );

        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_no_channels_found_dispatches_nothing(): void
    {
        Bus::fake();
        $site = $this->createSite();

        // No channels exist at all
        NotificationService::notifySiteEvent(
            site: $site,
            event: 'site_down',
            title: 'Site Down',
            message: 'Your site is down.',
            severity: 'critical',
        );

        Bus::assertNotDispatched(SendNotificationJob::class);
    }
}
