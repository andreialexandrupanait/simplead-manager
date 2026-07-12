<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\NotifyIncident;
use App\Jobs\ProcessNotificationBatch;
use App\Jobs\SendNotificationJob;
use App\Mail\UptimeAlertMail;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Services\Notifications\EmailNotificationSender;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NotificationDeliveryReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function slackChannel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::factory()->create(array_merge([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_active' => true,
        ], $overrides));
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    // ---------------------------------------------------------------------
    // P1-05 — recovery event routing + alias
    // ---------------------------------------------------------------------

    public function test_subscription_aliases_site_up_and_site_recovered_bidirectionally(): void
    {
        $legacy = new NotificationChannel(['event_subscriptions' => ['site_up']]);
        $this->assertTrue($legacy->subscribedTo('site_recovered'));
        $this->assertTrue($legacy->subscribedTo('site_up'));

        $canonical = new NotificationChannel(['event_subscriptions' => ['site_recovered']]);
        $this->assertTrue($canonical->subscribedTo('site_up'));
        $this->assertTrue($canonical->subscribedTo('site_recovered'));

        $unrelated = new NotificationChannel(['event_subscriptions' => ['site_down']]);
        $this->assertFalse($unrelated->subscribedTo('site_recovered'));

        $all = new NotificationChannel(['event_subscriptions' => null]);
        $this->assertTrue($all->subscribedTo('site_recovered'));
    }

    public function test_recovery_delivers_the_canonical_event_to_a_subscription_filtered_channel(): void
    {
        Queue::fake();
        Http::fake();

        // A channel that ONLY subscribes to the canonical recovery event — this is
        // exactly the case that silently never delivered before P1-05.
        $this->slackChannel(['is_default' => true, 'event_subscriptions' => ['site_recovered']]);

        $incident = UptimeIncident::factory()->resolved()->create();

        (new NotifyIncident($incident, 'recovery'))->handle();

        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->event === 'site_recovered' && $job->severity === 'success'
        );
    }

    // ---------------------------------------------------------------------
    // P1-24 — notify_down / notify_recovery toggles are honoured
    // ---------------------------------------------------------------------

    public function test_notify_recovery_toggle_off_suppresses_recovery_alerts(): void
    {
        Queue::fake();
        Http::fake();

        $this->settings()->set('notify_recovery', false, 'notifications', 'boolean');
        $this->slackChannel(['is_default' => true, 'event_subscriptions' => ['site_recovered']]);

        $incident = UptimeIncident::factory()->resolved()->create();

        (new NotifyIncident($incident, 'recovery'))->handle();

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_notify_down_toggle_off_suppresses_down_alerts(): void
    {
        Queue::fake();
        Http::fake();

        $this->settings()->set('notify_down', false, 'notifications', 'boolean');
        $this->slackChannel(['is_default' => true, 'event_subscriptions' => ['site_down']]);

        $incident = UptimeIncident::factory()->create();

        (new NotifyIncident($incident, 'down'))->handle();

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    // ---------------------------------------------------------------------
    // P1-20 — retries do not create duplicate logs
    // ---------------------------------------------------------------------

    public function test_retry_of_the_same_job_updates_one_log_row_instead_of_duplicating(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::sequence()
            ->push('nope', 500)   // attempt 1 fails
            ->push('ok', 200),    // retry succeeds
        ]);

        $job = new SendNotificationJob($this->slackChannel(), null, 'site_down', 'Site down', 'Details', [], 'critical');

        try {
            $job->handle(); // first attempt — throws
            $this->fail('Expected RuntimeException on the first (failed) attempt');
        } catch (\RuntimeException) {
            // expected
        }

        $job->handle(); // retry — same idempotency key, succeeds

        $this->assertSame(1, NotificationLog::count(), 'retries must not append duplicate log rows');
        $this->assertSame('sent', NotificationLog::sole()->status);
    }

    // ---------------------------------------------------------------------
    // P1-21 — quiet hours defer instead of annihilating
    // ---------------------------------------------------------------------

    public function test_quiet_hours_defers_channel_send_and_still_writes_in_app_record(): void
    {
        Queue::fake();

        $this->settings()->set('quiet_hours_enabled', true, 'notifications', 'boolean');
        $this->settings()->set('quiet_hours_start', '00:00', 'notifications', 'string');
        $this->settings()->set('quiet_hours_end', '23:59', 'notifications', 'string');

        $site = Site::factory()->create();
        $this->slackChannel(['is_default' => true, 'event_subscriptions' => null]);

        // defer() writes to the deferred Redis list — stub it so nothing is lost
        // and no immediate fallback dispatch happens.
        Redis::shouldReceive('rpush')->once()->andReturn(1);
        Redis::shouldReceive('expire')->andReturnTrue();

        NotificationService::notifySiteEvent($site, 'vulnerability_detected', 'Vuln', 'A vulnerability was found', [], 'warning');

        // Not dropped: deferred (no immediate send) + recorded in-app.
        Queue::assertNotPushed(SendNotificationJob::class);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $site->user_id]);
    }

    public function test_flush_deferred_is_a_noop_while_still_in_quiet_hours(): void
    {
        $this->settings()->set('quiet_hours_enabled', true, 'notifications', 'boolean');
        $this->settings()->set('quiet_hours_start', '00:00', 'notifications', 'string');
        $this->settings()->set('quiet_hours_end', '23:59', 'notifications', 'string');

        $this->assertSame(0, NotificationService::flushDeferred());
    }

    public function test_flush_deferred_dispatches_held_notifications_once_quiet_hours_end(): void
    {
        Queue::fake();

        // quiet hours off (default) — the window has ended.
        $channel = $this->slackChannel();

        $item = json_encode([
            'channel_id' => $channel->id,
            'site_id' => null,
            'event' => 'vulnerability_detected',
            'title' => 'Vuln',
            'message' => 'Details',
            'fields' => [],
            'severity' => 'warning',
            'webhook_payload' => null,
            'mailable_class' => null,
            'mailable_args' => null,
        ]);

        Redis::shouldReceive('rpoplpush')
            ->with('notification_deferred:processing', 'notification_deferred')
            ->andReturn(false);
        Redis::shouldReceive('rpoplpush')
            ->with('notification_deferred', 'notification_deferred:processing')
            ->andReturn($item, false);
        Redis::shouldReceive('lrem')->andReturnTrue();

        $this->assertSame(1, NotificationService::flushDeferred());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    // ---------------------------------------------------------------------
    // P1-22 — email sends synchronously so failures are visible
    // ---------------------------------------------------------------------

    public function test_email_sender_sends_synchronously_not_merely_queued(): void
    {
        Queue::fake();
        Mail::fake();

        $channel = NotificationChannel::factory()->create([
            'type' => 'email',
            'config' => ['address' => 'ops@example.com'],
            'is_active' => true,
        ]);

        $incident = UptimeIncident::factory()->resolved()->create();

        $result = EmailNotificationSender::send($channel, UptimeAlertMail::class, [$incident, 'recovery']);

        $this->assertTrue($result['success']);
        // The status must reflect an actual transport send, not a queue enqueue.
        Mail::assertSent(UptimeAlertMail::class);
        Mail::assertNotQueued(UptimeAlertMail::class);
    }

    // ---------------------------------------------------------------------
    // P1-54 — batch is at-least-once
    // ---------------------------------------------------------------------

    public function test_batch_recovers_items_stranded_in_processing_by_a_crashed_run(): void
    {
        Queue::fake();

        $channel = $this->slackChannel();

        $item = json_encode([
            'channel_id' => $channel->id,
            'site_id' => null,
            'event' => 'site_down',
            'title' => 'Site down',
            'message' => 'Details',
            'fields' => [],
            'severity' => 'critical',
            'webhook_payload' => null,
            'mailable_class' => null,
            'mailable_args' => null,
        ]);

        // Simulate an item left on the processing list by a previous crashed run:
        // the recover pass moves it back to the buffer, the drain re-reserves it,
        // and it is delivered — not lost.
        Redis::shouldReceive('rpoplpush')
            ->with('notification_buffer:processing', 'notification_buffer')
            ->andReturn($item, false);
        Redis::shouldReceive('rpoplpush')
            ->with('notification_buffer', 'notification_buffer:processing')
            ->andReturn($item, false);
        Redis::shouldReceive('lrem')->andReturnTrue();

        (new ProcessNotificationBatch)->handle();

        Queue::assertPushed(SendNotificationJob::class, 1);
    }
}
