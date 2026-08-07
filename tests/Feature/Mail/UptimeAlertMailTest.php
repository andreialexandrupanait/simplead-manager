<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Jobs\SendNotificationJob;
use App\Mail\UptimeAlertMail;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Models\User;
use App\Services\Notifications\EmailNotificationSender;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The uptime alert is the one email a client reads while something is on fire,
 * so its subject has to name the site, its body has to say what broke and when
 * in a timezone the reader recognises, and it has to arrive even when twenty
 * sites fall over together.
 */
class UptimeAlertMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::del('notification_buffer');
        Redis::del('notification_buffer:processing');
    }

    private function incident(string $cause = 'HTTP 502', ?string $resolvedAt = null): UptimeIncident
    {
        $site = Site::factory()->create(['name' => 'Example Shop']);
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'url' => 'https://www.example-shop.de/',
            'timeout' => 30,
        ]);

        return UptimeIncident::create([
            'monitor_id' => $monitor->id,
            'status' => $resolvedAt === null ? 'ongoing' : 'resolved',
            'cause' => $cause,
            'started_at' => Carbon::parse('2026-08-07 09:24:00', 'UTC'),
            'resolved_at' => $resolvedAt === null ? null : Carbon::parse($resolvedAt, 'UTC'),
        ]);
    }

    public function test_the_down_subject_names_the_url_and_the_verdict(): void
    {
        $mail = new UptimeAlertMail($this->incident(), 'down');

        $this->assertSame(
            'DOWN alert: https://www.example-shop.de/ appears down',
            $mail->envelope()->subject
        );
    }

    public function test_the_up_subject_says_the_site_is_back(): void
    {
        $incident = $this->incident('HTTP 502', '2026-08-07 09:28:00');
        $mail = new UptimeAlertMail($incident, 'recovery');

        $this->assertSame(
            'UP alert: https://www.example-shop.de/ is back online',
            $mail->envelope()->subject
        );
    }

    public function test_it_greets_the_account_behind_the_channel_address(): void
    {
        $user = User::factory()->create([
            'name' => 'Andrei Panait',
            'email' => 'andrei@example.com',
            'timezone' => 'Europe/Bucharest',
        ]);
        $channel = NotificationChannel::factory()->create(['type' => 'email', 'name' => 'Ops']);

        $mail = (new UptimeAlertMail($this->incident(), 'down'))
            ->withRecipient($user->email, $user, $channel);

        $html = $mail->render();

        $this->assertStringContainsString('Hi Andrei Panait,', $html);
        // 09:24 UTC is 12:24 in Bucharest — the hardcoded "UTC" of the old
        // template made this the one number in the email that was reliably wrong.
        $this->assertStringContainsString('Aug 7, 2026 12:24pm', $html);
        $this->assertStringContainsString('Europe/Bucharest', $html);
    }

    public function test_an_unknown_address_falls_back_to_the_channel_name_then_to_a_generic_greeting(): void
    {
        $channel = NotificationChannel::factory()->create(['type' => 'email', 'name' => 'Alerts Ops']);

        $named = (new UptimeAlertMail($this->incident(), 'down'))
            ->withRecipient('nobody@example.com', null, $channel);
        $this->assertStringContainsString('Hi Alerts Ops,', $named->render());

        $channel->name = '';
        $anonymous = (new UptimeAlertMail($this->incident(), 'down'))
            ->withRecipient('nobody@example.com', null, $channel);
        $this->assertStringContainsString('Hi there,', $anonymous->render());
    }

    public function test_a_recognised_status_code_becomes_a_sentence_and_the_second_location_gets_its_own_line(): void
    {
        $mail = new UptimeAlertMail($this->incident('HTTP 502 (confirmed from FRA)'), 'down');
        $html = $mail->render();

        $this->assertStringContainsString('is returning HTTP 502 Bad Gateway', $html);
        $this->assertStringContainsString('We confirmed this independently from our FRA probe.', $html);
        // The parenthetical must not survive alongside its expanded form.
        $this->assertStringNotContainsString('(confirmed from FRA)', $html);
    }

    public function test_an_unrecognised_cause_is_quoted_verbatim_rather_than_flattened(): void
    {
        $mail = new UptimeAlertMail($this->incident('Error: connect ECONNREFUSED 10.0.0.1:8443'), 'down');

        $this->assertStringContainsString('Error: connect ECONNREFUSED 10.0.0.1:8443', $mail->render());
    }

    public function test_the_recovery_body_states_how_long_the_site_was_down(): void
    {
        $incident = $this->incident('HTTP 502', '2026-08-07 09:28:00');
        $mail = new UptimeAlertMail($incident, 'recovery');
        $html = $mail->render();

        $this->assertStringContainsString('was down for 4m', $html);
        $this->assertStringContainsString('back up and running', $html);
        $this->assertStringContainsString('If you fixed the issue, nice work!', $html);
    }

    public function test_the_plain_text_alternative_carries_the_same_facts(): void
    {
        $mail = new UptimeAlertMail($this->incident(), 'down');
        $text = view('mail.uptime-alert-text', $mail->payload())->render();

        $this->assertStringContainsString('DOWN alert: https://www.example-shop.de/ appears down', $text);
        $this->assertStringContainsString('is returning HTTP 502 Bad Gateway', $text);
        $this->assertStringNotContainsString('<a ', $text);
    }

    public function test_the_sender_personalises_the_mailable_before_dispatching_it(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Andrei Panait', 'email' => 'andrei@example.com']);
        $channel = NotificationChannel::factory()->create([
            'type' => 'email',
            'config' => ['address' => 'ANDREI@example.com'],
        ]);

        EmailNotificationSender::send($channel, UptimeAlertMail::class, [$this->incident(), 'down']);

        Mail::assertSent(UptimeAlertMail::class, fn (UptimeAlertMail $mail) => $mail->recipientName === $user->name
            && $mail->recipientAddress === 'ANDREI@example.com');
    }

    public function test_the_logo_travels_as_an_inline_attachment_not_a_data_uri(): void
    {
        // Gmail drops <img src="data:...">, so a data URI would show a broken
        // image to most recipients. The logo has to leave as a real inline
        // (CID) part of the message.
        Mail::mailer('array')->to('ops@example.com')->send(new UptimeAlertMail($this->incident(), 'down'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $email = $sent[0]->getOriginalMessage();
        $html = (string) $email->getHtmlBody();

        $this->assertStringNotContainsString('src="data:image', $html);
        $this->assertSame(1, preg_match('/src="cid:([^"]+)"/', $html, $m), 'the logo should be referenced by content ID');

        // A cid: that points at no part is a broken image, so assert the
        // reference actually resolves to an attached one.
        $contentIds = array_map(fn ($part) => $part->getContentId(), $email->getAttachments());
        $this->assertContains($m[1], $contentIds, 'the referenced content ID should belong to an attached part');
    }

    public function test_the_down_alert_says_what_to_check_for_this_particular_failure(): void
    {
        $gateway = (new UptimeAlertMail($this->incident('HTTP 502'), 'down'))->render();
        $this->assertStringContainsString('What to check first', $gateway);
        $this->assertStringContainsString('restart PHP-FPM', $gateway);

        $dns = (new UptimeAlertMail($this->incident('cURL error 6: Could not resolve host: shop.example'), 'down'))->render();
        $this->assertStringContainsString('nameservers still point where they should', $dns);
        $this->assertStringNotContainsString('restart PHP-FPM', $dns);
    }

    public function test_the_recovery_alert_carries_no_troubleshooting_block(): void
    {
        $incident = $this->incident('HTTP 502', '2026-08-07 09:28:00');

        $this->assertStringNotContainsString('What to check first', (new UptimeAlertMail($incident, 'recovery'))->render());
    }

    public function test_the_mailable_must_never_become_queueable(): void
    {
        // Mailer::sendMailable() silently turns send() into queue() when the
        // mailable is ShouldQueue. EmailNotificationSender's synchronous send
        // exists precisely so a dead SMTP transport is caught and logged (P1-22);
        // queueing would report success before the transport is even touched.
        $this->assertNotInstanceOf(ShouldQueue::class, new UptimeAlertMail($this->incident(), 'down'));
    }

    public function test_an_email_channel_gets_one_alert_per_site_during_a_storm(): void
    {
        config()->set('monitoring.aggregate_alert_storms', true);
        Queue::fake();

        NotificationChannel::factory()->create([
            'type' => 'email',
            'config' => ['address' => 'ops@example.com'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);

        foreach (range(1, 3) as $i) {
            $site = Site::factory()->create(['user_id' => User::factory()->create()->id]);
            NotificationService::notifySiteEvent(
                $site,
                'site_down',
                'Down',
                "{$site->name} is down",
                severity: 'critical',
                mailableClass: UptimeAlertMail::class,
                mailableArgs: [],
            );
        }

        // Three sends, each still carrying the mailable — the grouped path drops
        // it and the channel fails with "No mailable class provided".
        Queue::assertPushed(SendNotificationJob::class, 3);
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->mailableClass === UptimeAlertMail::class,
        );
    }
}
