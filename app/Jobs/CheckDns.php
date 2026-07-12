<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DnsChange;
use App\Models\DnsMonitor;
use App\Services\ActivityLogger;
use App\Services\DnsSelectorDiscoveryService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckDns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Bounded worker timeout. A full check fans out ~10 blocking
     * dns_get_record() lookups (A/AAAA/MX/NS/CNAME/TXT + DMARC + per-selector
     * DKIM), each of which can hang on a dead resolver. Capping the job means a
     * hung check is killed rather than pinning a worker indefinitely — and,
     * paired with next_check_at being advanced in failed(), a hanging monitor
     * no longer gets re-dispatched every minute (P2-41, same class as U-01/E-27).
     */
    public int $timeout = 90;

    public int $uniqueFor = 270; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    /**
     * Consecutive failed checks before a "DNS monitoring is broken" alert fires.
     */
    private const ALERT_AFTER_FAILURES = 3;

    /** Fields that reset the persisted failure state on any successful check. */
    private const CLEARED_ERROR_STATE = [
        'consecutive_failures' => 0,
        'last_error' => null,
        'last_error_at' => null,
    ];

    /** @var array<string, string> map of selector => source ('manual'|'cloudflare'|'postmark'|'fallback') */
    private array $selectorSources = [];

    public function __construct(
        public DnsMonitor $monitor,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-dns-'.$this->monitor->id;
    }

    public function handle(): void
    {
        $domain = $this->monitor->domain;
        $nextCheckAt = now()->addMinutes($this->monitor->interval_minutes);

        try {
            [$records, $failedTypes] = $this->fetchDnsRecords($domain);

            $confirmed = $this->monitor->current_records;

            // Carry forward the last confirmed value for any record type whose
            // lookup transiently failed (resolver returned false). This prevents
            // a DNS blip from being read as "records deleted" â both for change
            // detection and for the value that flows into client reports.
            if ($confirmed !== null) {
                foreach ($failedTypes as $label) {
                    if (array_key_exists($label, $confirmed)) {
                        $records[$label] = $confirmed[$label];
                    }
                }
            }

            // First-ever check: just record the baseline, never alert.
            if ($confirmed === null) {
                $this->monitor->update([
                    'current_records' => $records,
                    'pending_records' => null,
                    'has_changes' => false,
                    'last_checked_at' => now(),
                    'next_check_at' => $nextCheckAt,
                    ...self::CLEARED_ERROR_STATE,
                ]);

                return;
            }

            $changes = $this->detectChanges($confirmed, $records);

            // Stable â matches the confirmed state. Refresh timestamps and clear
            // any half-observed candidate.
            if (empty($changes)) {
                $this->monitor->update([
                    'current_records' => $records,
                    'pending_records' => null,
                    'has_changes' => false,
                    'last_checked_at' => now(),
                    'next_check_at' => $nextCheckAt,
                    ...self::CLEARED_ERROR_STATE,
                ]);

                return;
            }

            // A change vs the confirmed state â require two consecutive identical
            // observations before acting. On the first sighting, hold it as a
            // pending candidate and wait for the next check.
            $pending = $this->monitor->pending_records;
            $confirmedThisTime = $pending !== null && empty($this->detectChanges($pending, $records));

            if (! $confirmedThisTime) {
                $this->monitor->update([
                    'pending_records' => $records,
                    'last_checked_at' => now(),
                    'next_check_at' => $nextCheckAt,
                    ...self::CLEARED_ERROR_STATE,
                ]);

                return;
            }

            // Second consecutive identical observation â commit the change.
            $this->monitor->update([
                'previous_records' => $confirmed,
                'current_records' => $records,
                'pending_records' => null,
                'has_changes' => true,
                'last_checked_at' => now(),
                'next_check_at' => $nextCheckAt,
                ...self::CLEARED_ERROR_STATE,
            ]);

            foreach ($changes as $change) {
                DnsChange::create([
                    'dns_monitor_id' => $this->monitor->id,
                    'record_type' => $change['type'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'detected_at' => now(),
                ]);
            }

            if ($this->monitor->site) {
                $types = implode(', ', array_column($changes, 'type'));

                NotificationService::notifySiteEvent(
                    $this->monitor->site,
                    'dns_changed',
                    'DNS Records Updated',
                    "DNS records updated for {$domain}: {$types}",
                    ['Domain' => $domain, 'Updated Records' => $types, 'Updates' => count($changes)],
                    'info'
                );

                ActivityLogger::log(
                    type: 'dns',
                    severity: 'info',
                    title: "DNS records updated: {$types}",
                    description: "DNS update detected for {$domain}",
                    site: $this->monitor->site,
                    icon: 'globe',
                );
            }
        } catch (\Throwable $e) {
            Log::warning("DNS check failed for {$domain}: {$e->getMessage()}");

            // Record a visible, queryable error state (P2-42) and still advance
            // next_check_at (P2-41) so a broken monitor is neither invisible nor
            // re-dispatched every minute. NotificationService is fired via
            // notifyFailure() only when the failure first crosses the threshold.
            $this->recordFailure($e->getMessage());
        }
    }

    /**
     * A hard worker timeout / kill never reaches handle()'s catch, so
     * next_check_at would stay past-due and the dispatcher would relaunch a
     * hanging check every minute (P2-41). Advance it and record the error state
     * (P2-42) here too, mirroring CheckUptime::failed().
     */
    public function failed(?\Throwable $exception): void
    {
        try {
            $this->recordFailure(
                $exception?->getMessage() ?? 'DNS check aborted (timeout or worker killed)'
            );
        } catch (\Throwable $e) {
            Log::error('Failed to record DNS check failure for monitor '.$this->monitor->id.': '.$e->getMessage());
        }
    }

    private function recordFailure(string $message): void
    {
        $failures = ($this->monitor->consecutive_failures ?? 0) + 1;

        $this->monitor->update([
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
            'consecutive_failures' => $failures,
            'last_error' => Str::limit($message, 250),
            'last_error_at' => now(),
        ]);

        // Alert once, when the failure first crosses the threshold, so a
        // persistently broken DNS monitor becomes visible without spamming.
        $site = $this->monitor->site;
        if ($failures === self::ALERT_AFTER_FAILURES && $site !== null) {
            NotificationService::notifySiteEventSlim(
                site: $site,
                event: 'dns_check_failed',
                summary: "\xF0\x9F\x9B\x91 DNS · *{$site->name}* — DNS checks are failing for {$this->monitor->domain}.",
                deepLink: '<'.route('sites.overview', $site).'|Open site →>',
                severity: 'warning',
            );
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>} the records and the
     *                                                         list of record-type
     *                                                         labels whose lookup
     *                                                         transiently failed
     */
    protected function fetchDnsRecords(string $domain): array
    {
        $records = [];
        $failed = [];

        $types = [
            DNS_A => 'A',
            DNS_AAAA => 'AAAA',
            DNS_MX => 'MX',
            DNS_NS => 'NS',
            DNS_CNAME => 'CNAME',
            DNS_TXT => 'TXT',
        ];

        foreach ($types as $dnsType => $label) {
            $result = @dns_get_record($domain, $dnsType);

            if ($result === false) {
                // Transient resolver failure â placeholder is overwritten by the
                // carried-forward value in handle(); flag so it is not compared.
                $records[$label] = [];
                $failed[] = $label;

                continue;
            }

            $records[$label] = collect($result)->map(function ($r) use ($label) {
                return match ($label) {
                    'A' => $r['ip'] ?? null,
                    'AAAA' => $r['ipv6'] ?? null,
                    'MX' => ['pri' => $r['pri'] ?? 0, 'target' => $r['target'] ?? ''],
                    'NS' => $r['target'] ?? null,
                    'CNAME' => $r['target'] ?? null,
                    'TXT' => $r['txt'] ?? null,
                    default => null,
                };
            })->filter()->sort()->values()->all();
        }

        [$dmarc, $dmarcFailed] = $this->fetchDmarcRecords($domain);
        $records['DMARC'] = $dmarc;
        if ($dmarcFailed) {
            $failed[] = 'DMARC';
        }

        [$dkim, $dkimFailed] = $this->fetchDkimRecords($domain);
        $records['DKIM'] = $dkim;
        if ($dkimFailed) {
            $failed[] = 'DKIM';
        }

        return [$records, $failed];
    }

    /**
     * @return array{0: list<string>, 1: bool} the DMARC records and whether the
     *                                         lookup transiently failed
     */
    private function fetchDmarcRecords(string $domain): array
    {
        $result = @dns_get_record('_dmarc.'.$domain, DNS_TXT);

        // false = resolver error (transient); [] = genuinely no DMARC record.
        if ($result === false) {
            return [[], true];
        }

        if ($result === []) {
            return [[], false];
        }

        $records = collect($result)
            ->map(fn ($r) => $r['txt'] ?? null)
            ->filter(fn ($txt) => is_string($txt) && stripos($txt, 'v=DMARC1') !== false)
            ->sort()
            ->values()
            ->all();

        return [$records, false];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool} the DKIM records and
     *                                                       whether a lookup
     *                                                       transiently failed
     */
    private function fetchDkimRecords(string $domain): array
    {
        $discovery = app(DnsSelectorDiscoveryService::class);
        $sources = $discovery->discoverFor($this->monitor);
        $selectors = $discovery->flatten($sources);

        $found = [];
        $confirmedSelectors = [];
        $hadResolverError = false;

        foreach ($selectors as $selector) {
            $result = @dns_get_record($selector.'._domainkey.'.$domain, DNS_TXT);

            if ($result === false) {
                $hadResolverError = true;

                continue;
            }

            if ($result === []) {
                continue;
            }

            foreach ($result as $r) {
                $txt = $r['txt'] ?? null;

                if (! is_string($txt) || stripos($txt, 'v=DKIM1') === false) {
                    continue;
                }

                $source = $discovery->sourceFor($selector, $sources);

                $found[] = [
                    'selector' => $selector,
                    'value' => $txt,
                    'source' => $source,
                ];

                $confirmedSelectors[] = $selector;
            }
        }

        usort($found, fn ($a, $b) => strcmp($a['selector'], $b['selector']));

        $this->persistDiscoveredSelectors($confirmedSelectors);

        // Treat as a transient failure only when a resolver error occurred and
        // nothing was found â otherwise partial/complete results are trustworthy.
        return [$found, $hadResolverError && $found === []];
    }

    private function persistDiscoveredSelectors(array $confirmed): void
    {
        if ($confirmed === []) {
            return;
        }

        $existing = is_array($this->monitor->dkim_selectors) ? $this->monitor->dkim_selectors : [];
        $merged = array_values(array_unique(array_filter(
            array_merge($existing, $confirmed),
            fn ($s) => is_string($s) && $s !== '',
        )));

        if (count($merged) > 20) {
            $merged = array_slice($merged, 0, 20);
        }

        sort($merged);
        $sortedExisting = $existing;
        sort($sortedExisting);

        if ($merged !== $sortedExisting) {
            $this->monitor->dkim_selectors = $merged;
        }
    }

    private function detectChanges(array $old, array $new): array
    {
        $changes = [];

        $allTypes = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allTypes as $type) {
            $oldRecords = $this->normalizeForComparison($old[$type] ?? []);
            $newRecords = $this->normalizeForComparison($new[$type] ?? []);

            if ($oldRecords !== $newRecords) {
                $changes[] = [
                    'type' => $type,
                    'old' => $old[$type] ?? [],
                    'new' => $new[$type] ?? [],
                ];
            }
        }

        return $changes;
    }

    private function normalizeForComparison(array $data): string
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = mb_strtolower($value);
            }
        });

        $normalize = function ($item) use (&$normalize) {
            if (is_array($item)) {
                if (array_is_list($item)) {
                    $item = array_map($normalize, $item);
                    $item = array_map(fn ($v) => json_encode($v), $item);
                    sort($item);

                    return $item;
                }
                ksort($item);

                return array_map($normalize, $item);
            }

            return $item;
        };

        return json_encode($normalize($data));
    }
}
