<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Contracts\PersonalizedMailable;
use App\Models\NotificationChannel;
use App\Models\UptimeIncident;
use App\Models\User;
use App\Services\Branding\EmailBrandingService;
use App\Services\Uptime\FailureReasonPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The DOWN / UP alert.
 *
 * Deliberately NOT ShouldQueue. EmailNotificationSender already runs inside the
 * queued SendNotificationJob and sends synchronously so a transport failure is
 * caught and logged (see the P1-22 note there); queueing the mailable would
 * hand delivery back to a second worker and restore exactly the blindness that
 * note describes.
 */
class UptimeAlertMail extends Mailable implements PersonalizedMailable
{
    use Queueable, SerializesModels;

    public ?string $recipientAddress = null;

    public ?string $recipientName = null;

    public ?string $recipientTimezone = null;

    public function __construct(
        public UptimeIncident $incident,
        public string $type
    ) {}

    public function withRecipient(string $address, ?User $user, NotificationChannel $channel): static
    {
        $this->recipientAddress = $address;
        $this->recipientName = $user?->name ?: ($channel->name ?: null);
        $this->recipientTimezone = $user?->timezone;

        if ($user?->language) {
            $this->locale($user->language);
        }

        return $this;
    }

    public function envelope(): Envelope
    {
        $url = (string) $this->incident->monitor->url;

        return new Envelope(
            subject: $this->isDown()
                ? __('DOWN alert: :url appears down', ['url' => $url])
                : __('UP alert: :url is back online', ['url' => $url]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.uptime-alert',
            text: 'mail.uptime-alert-text',
            with: $this->payload(),
        );
    }

    /**
     * Everything both the HTML and the plain-text view render, resolved once so
     * the two cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $monitor = $this->incident->monitor;
        $site = $monitor->site;
        $isDown = $this->isDown();

        $moment = $isDown
            ? $this->incident->started_at
            : ($this->incident->resolved_at ?? $this->incident->started_at);

        return [
            'isDown' => $isDown,
            'site' => $site,
            'siteUrl' => (string) $monitor->url,
            'greetingName' => $this->recipientName,
            'recipientAddress' => $this->recipientAddress,
            'momentLabel' => $this->formatMoment($moment),
            'timezoneLabel' => $this->timezone(),
            'causeClause' => FailureReasonPresenter::clause(
                $this->incident->cause,
                ((int) $monitor->timeout) ?: null,
            ),
            'remedies' => $isDown
                ? FailureReasonPresenter::remedies($this->incident->cause, ((int) $monitor->timeout) ?: null)
                : [],
            'confirmation' => FailureReasonPresenter::confirmation($this->incident->cause),
            'duration' => $this->incident->duration,
            'uptimeUrl' => route('sites.uptime', $site),
            'settingsUrl' => route('settings.notifications'),
            'appName' => app(EmailBrandingService::class)->appName(),
        ];
    }

    private function isDown(): bool
    {
        return $this->type === 'down';
    }

    /**
     * "Aug 7, 2026 12:24pm" in English, "7 aug. 2026, 12:24" in Romanian — in
     * the recipient's timezone either way.
     *
     * The pattern itself is translatable because a 12-hour clock and a
     * month-first order are English conventions, not universal ones; leaving
     * them fixed would put "Aug 7, 2026 12:24pm" in the middle of a Romanian
     * sentence.
     */
    private function formatMoment(?Carbon $moment): string
    {
        if ($moment === null) {
            return '';
        }

        return $moment->copy()
            ->setTimezone($this->timezone())
            ->locale(app()->getLocale())
            ->isoFormat(__('MMM D, YYYY h:mma'));
    }

    /**
     * The app timezone is the fallback, never a hardcoded "UTC" — the old
     * template printed that literal next to a timestamp it had not converted.
     */
    private function timezone(): string
    {
        $candidate = $this->recipientTimezone ?: (string) config('app.timezone', 'UTC');

        return in_array($candidate, timezone_identifiers_list(), true) ? $candidate : 'UTC';
    }
}
