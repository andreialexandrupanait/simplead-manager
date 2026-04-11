<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DnsChange;
use App\Models\DnsMonitor;
use App\Services\ActivityLogger;
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

    public int $timeout = 60;

    public function __construct(
        public DnsMonitor $monitor,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-dns-' . $this->monitor->id;
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
                    'DNS Records Changed',
                    "DNS records changed for {$domain}: {$types}",
                    ['Domain' => $domain, 'Changed Records' => $types, 'Changes' => count($changes)],
                    'warning'
                );

                ActivityLogger::log(
                    type: 'dns',
                    severity: 'warning',
                    title: "DNS records changed: {$types}",
                    description: "DNS change detected for {$domain}",
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
                    'MX' => ['target' => $r['target'] ?? '', 'pri' => $r['pri'] ?? 0],
                    'NS' => $r['target'] ?? null,
                    'CNAME' => $r['target'] ?? null,
                    'TXT' => $r['txt'] ?? null,
                    default => null,
                };
            })->filter()->sort()->values()->all();
        }

        return $records;
    }

    private function detectChanges(array $old, array $new): array
    {
        $changes = [];

        $allTypes = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allTypes as $type) {
            $oldRecords = json_encode($old[$type] ?? []);
            $newRecords = json_encode($new[$type] ?? []);

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
}
