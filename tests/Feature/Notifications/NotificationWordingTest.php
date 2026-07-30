<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyIncident;
use App\Models\InAppNotification;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * In-app notifications are built by stripping the Slack mrkdwn out of the same
 * string that goes to a channel, so a terse log line there reads as a terse log
 * line in the bell menu — "🔴 Site down · *name* — cause" is not something a
 * person says.
 *
 * These assert the sentences, and that no Slack decoration survives into the
 * stored text.
 */
class NotificationWordingTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithChannel(): Site
    {
        NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);

        return Site::factory()->create([
            'url' => 'https://www.m1-shop.de',
            'name' => 'M1 Shop',
            'user_id' => User::factory(),
        ]);
    }

    private function incidentFor(Site $site, bool $resolved): UptimeIncident
    {
        $monitor = UptimeMonitor::factory()->create(['site_id' => $site->id]);

        return UptimeIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => 'HTTP 502',
            'started_at' => now()->subMinutes(5),
            'resolved_at' => $resolved ? now() : null,
        ]);
    }

    public function test_a_recovery_reads_as_a_sentence_with_the_downtime(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: true), 'recovery'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();
        $text = $stored->title.' '.$stored->message;

        $this->assertStringContainsString('www.m1-shop.de', $text);
        $this->assertStringContainsString('is back up', $text);
        $this->assertStringContainsString('5m', $text, 'The downtime is the point of the message.');
    }

    public function test_a_downtime_alert_names_the_site_and_the_cause(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: false), 'down'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();
        $text = $stored->title.' '.$stored->message;

        $this->assertStringContainsString('www.m1-shop.de', $text);
        $this->assertStringContainsString('has gone down', $text);
        $this->assertStringContainsString('HTTP 502', $text);
    }

    public function test_no_slack_decoration_survives_into_the_stored_text(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: true), 'recovery'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();
        $text = $stored->title.' '.$stored->message;

        $this->assertStringNotContainsString('*', $text);
        $this->assertStringNotContainsString('·', $text, 'The mid-dot separator was a Slack-ism.');
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, 'No emoji in the stored text.');
    }

    public function test_the_deep_link_becomes_a_url_on_the_notification_not_body_text(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: false), 'down'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();

        // The row links to the site's uptime screen…
        $this->assertSame(route('sites.uptime', $site), $stored->data['url'] ?? null);

        // …instead of printing "Open uptime →" as a paragraph that looks like a
        // link and isn't one.
        $this->assertStringNotContainsString('Open uptime', $stored->message ?? '');
    }

    public function test_a_notification_without_a_deep_link_carries_no_url(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: true), 'recovery'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('url', $stored->data);
    }

    public function test_the_site_is_identified_by_domain_not_display_name(): void
    {
        Queue::fake();
        $site = $this->siteWithChannel();

        (new NotifyIncident($this->incidentFor($site, resolved: true), 'recovery'))->handle();

        $stored = InAppNotification::latest('id')->firstOrFail();

        // "M1 Shop" is what we call it; "www.m1-shop.de" is what the client
        // recognises.
        $this->assertStringContainsString('www.m1-shop.de', $stored->title.' '.$stored->message);
    }
}
