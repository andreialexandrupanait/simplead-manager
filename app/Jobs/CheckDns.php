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

class CheckDns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

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

        try {
            $records = $this->fetchDnsRecords($domain);

            $previousRecords = $this->monitor->current_records;
            $changes = [];

            if ($previousRecords !== null) {
                $changes = $this->detectChanges($previousRecords, $records);
            }

            $this->monitor->update([
                'previous_records' => $previousRecords,
                'current_records' => $records,
                'has_changes' => ! empty($changes),
                'last_checked_at' => now(),
                'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
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

            if (! empty($changes) && $this->monitor->site) {
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

            $this->monitor->update([
                'last_checked_at' => now(),
                'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
            ]);
        }
    }

    private function fetchDnsRecords(string $domain): array
    {
        $records = [];

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
                $records[$label] = [];

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

        $records['DMARC'] = $this->fetchDmarcRecords($domain);
        $records['DKIM'] = $this->fetchDkimRecords($domain);

        return $records;
    }

    private function fetchDmarcRecords(string $domain): array
    {
        $result = @dns_get_record('_dmarc.'.$domain, DNS_TXT);

        if (! is_array($result) || $result === []) {
            return [];
        }

        return collect($result)
            ->map(fn ($r) => $r['txt'] ?? null)
            ->filter(fn ($txt) => is_string($txt) && stripos($txt, 'v=DMARC1') !== false)
            ->sort()
            ->values()
            ->all();
    }

    private function fetchDkimRecords(string $domain): array
    {
        $discovery = app(DnsSelectorDiscoveryService::class);
        $sources = $discovery->discoverFor($this->monitor);
        $selectors = $discovery->flatten($sources);

        $found = [];
        $confirmedSelectors = [];

        foreach ($selectors as $selector) {
            $result = @dns_get_record($selector.'._domainkey.'.$domain, DNS_TXT);

            if (! is_array($result) || $result === []) {
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

        return $found;
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
