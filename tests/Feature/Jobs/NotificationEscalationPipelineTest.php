<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessNotificationBatch;
use App\Jobs\ProcessNotificationEscalations;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\NotificationEscalationRule;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NotificationEscalationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function slackChannel(): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_active' => true,
        ]);
    }

    private function logFor(NotificationChannel $channel, array $overrides = []): NotificationLog
    {
        $log = new NotificationLog(array_merge([
            'notification_channel_id' => $channel->id,
            'event' => 'site_down',
            'channel_type' => $channel->type,
            'status' => 'sent',
            'message' => 'Site is down',
            'severity' => 'critical',
            'escalated' => false,
            'metadata' => ['title' => 'Site down', 'severity' => 'critical'],
        ], $overrides));

        $log->created_at = now()->subMinutes(30);
        $log->save();

        return $log;
    }

    /** N-P1-2: the ack link must actually be delivered in the outgoing message. */
    public function test_critical_notification_embeds_ack_link_matching_the_stored_token(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        $channel = $this->slackChannel();

        (new SendNotificationJob($channel, null, 'site_down', 'Site down', 'Details', [], 'critical'))->handle();

        $log = NotificationLog::sole();
        $this->assertNotNull($log->ack_token);

        // The token (not the full URL) — JSON bodies escape slashes.
        Http::assertSent(fn ($request) => str_contains($request->body(), $log->ack_token));
    }

    public function test_info_notification_gets_no_ack_token_and_no_ack_link(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        (new SendNotificationJob($this->slackChannel(), null, 'report_ready', 'Report', 'Done', [], 'info'))->handle();

        $this->assertNull(NotificationLog::sole()->ack_token);
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'Acknowledge:'));
    }

    /** N-P1-1: the final failed attempt must throw, not silently succeed. */
    public function test_failed_send_records_failed_log_and_still_throws(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('nope', 500)]);

        $job = new SendNotificationJob($this->slackChannel(), null, 'site_down', 'Site down', 'Details', [], 'critical');

        try {
            $job->handle();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException) {
            // expected — the job must surface the failure on every attempt
        }

        $this->assertSame('failed', NotificationLog::sole()->status);
    }

    /** N-P1-1: alerts whose primary delivery failed must still escalate. */
    public function test_escalation_picks_up_failed_sends(): void
    {
        Queue::fake();

        $source = $this->slackChannel();
        $target = $this->slackChannel();

        NotificationEscalationRule::create([
            'source_channel_id' => $source->id,
            'escalation_channel_id' => $target->id,
            'delay_minutes' => 15,
            'severity' => 'critical',
            'is_active' => true,
        ]);

        $log = $this->logFor($source, ['status' => 'failed']);

        (new ProcessNotificationEscalations)->handle();

        Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($target) {
            return $job->channel->id === $target->id
                && $job->isEscalation === true
                && str_contains($job->message, 'FAILED');
        });
        $this->assertTrue($log->fresh()->escalated);
    }

    public function test_acknowledged_notifications_are_not_escalated(): void
    {
        Queue::fake();

        $source = $this->slackChannel();
        $target = $this->slackChannel();

        NotificationEscalationRule::create([
            'source_channel_id' => $source->id,
            'escalation_channel_id' => $target->id,
            'delay_minutes' => 15,
            'severity' => 'critical',
            'is_active' => true,
        ]);

        $this->logFor($source, ['acknowledged_at' => now()->subMinutes(5)]);

        (new ProcessNotificationEscalations)->handle();

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    /** N-P1-2: A→B + B→A rule pairs must not loop forever. */
    public function test_escalation_generated_notifications_are_born_escalated_and_never_re_escalated(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        $channelA = $this->slackChannel();
        $channelB = $this->slackChannel();

        // Simulate the escalation-generated send landing on channel B.
        (new SendNotificationJob(
            $channelB, null, 'site_down', '[ESCALATION] Site down', 'Details', [], 'critical',
            isEscalation: true,
        ))->handle();

        $log = NotificationLog::sole();
        $this->assertTrue($log->escalated);

        // A reverse rule (B→A) must not pick it up.
        NotificationEscalationRule::create([
            'source_channel_id' => $channelB->id,
            'escalation_channel_id' => $channelA->id,
            'delay_minutes' => 0,
            'severity' => 'critical',
            'is_active' => true,
        ]);
        $log->created_at = now()->subMinutes(30);
        $log->save();

        Queue::fake();
        (new ProcessNotificationEscalations)->handle();

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    /** Prod regression: malformed buffer items crashed the whole batch. */
    public function test_malformed_buffer_items_are_skipped_without_crashing_the_batch(): void
    {
        Queue::fake();

        $channel = $this->slackChannel();

        $valid = json_encode([
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

        Redis::shouldReceive('lpop')
            ->with('notification_buffer')
            ->andReturn(json_encode(['event' => 'orphan-without-channel-id']), '"just-a-string"', $valid, false);

        (new ProcessNotificationBatch)->handle();

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_ack_endpoint_marks_the_log_acknowledged(): void
    {
        $this->withoutVite(); // the acknowledged view uses @vite; CI has no built assets

        $log = $this->logFor($this->slackChannel(), ['ack_token' => str_repeat('a', 64)]);

        $this->get(route('notifications.ack', str_repeat('a', 64)))->assertOk();

        $this->assertNotNull($log->fresh()->acknowledged_at);
    }
}
