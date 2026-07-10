<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DomainStatus;
use App\Models\Site;
use App\Services\DomainExpiryService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckDomainExpiry implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public Site $site)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'domain-expiry-'.$this->site->id;
    }

    public function handle(): void
    {
        $result = DomainExpiryService::check($this->site);

        // Domain expiry is a third-party (RDAP) signal, not site reachability —
        // deliberately does NOT touch the circuit breaker (see E-13).
        $this->site->updateQuietly([
            'domain_status' => $result['status']->value,
            'domain_expires_at' => $result['expires_at'],
            'domain_registrar' => $result['registrar'],
            'domain_checked_at' => now(),
            'domain_last_error' => $result['error'],
        ]);

        if (! in_array($result['status'], [DomainStatus::ExpiringSoon, DomainStatus::Expired], true)) {
            return;
        }

        $expiresAt = $result['expires_at'];
        $when = $expiresAt
            ? ($expiresAt->isPast() ? 'expired '.$expiresAt->diffForHumans() : 'expires '.$expiresAt->diffForHumans())
            : 'is expiring';
        $summary = "\xF0\x9F\x8C\x90 Domain · *{$this->site->name}* — the domain {$when}.";

        NotificationService::notifySiteEventSlim(
            site: $this->site,
            event: 'domain_expiring',
            summary: $summary,
            deepLink: '<'.route('sites.overview', $this->site).'|Open site →>',
            severity: $result['status'] === DomainStatus::Expired ? 'critical' : 'warning',
        );
    }
}
