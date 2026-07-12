<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DnsMonitor;
use Illuminate\Support\Facades\Log;

class DnsSelectorDiscoveryService
{
    public const COMMON_DKIM_SELECTORS = [
        'default', 'google', 'selector1', 'selector2',
        's1', 's2', 'k1', 'k2', 'k3', 'mail', 'dkim',
        'pm', 'postmark', 'mandrill', 'mailgun',
        'mxvault', 'everlytickey1', 'everlytickey2',
        'smtpapi', 'sm', 'fd', 'fd2',
    ];

    public function __construct(
        private PostmarkService $postmark,
    ) {}

    /**
     * Returns selectors grouped by source for a given monitor.
     *
     * @return array{manual: list<string>, cloudflare: list<string>, postmark: list<string>, fallback: list<string>}
     */
    public function discoverFor(DnsMonitor $monitor): array
    {
        return [
            'manual' => $this->normalizeList($monitor->dkim_selectors ?? []),
            'cloudflare' => $this->fromCloudflare($monitor),
            'postmark' => $this->fromPostmark($monitor),
            'fallback' => self::COMMON_DKIM_SELECTORS,
        ];
    }

    /**
     * Flattens grouped results into a single deduplicated list of selectors.
     *
     * @param  array{manual: list<string>, cloudflare: list<string>, postmark: list<string>, fallback: list<string>}  $sources
     * @return list<string>
     */
    public function flatten(array $sources): array
    {
        $merged = array_merge(
            $sources['manual'],
            $sources['cloudflare'],
            $sources['postmark'],
            $sources['fallback'],
        );

        return array_values(array_unique(array_filter($merged, fn ($s) => $s !== '')));
    }

    /**
     * Returns the discovery source for a given selector (first match wins).
     *
     * @param  array{manual: list<string>, cloudflare: list<string>, postmark: list<string>, fallback: list<string>}  $sources
     */
    public function sourceFor(string $selector, array $sources): string
    {
        foreach (['manual', 'cloudflare', 'postmark', 'fallback'] as $key) {
            if (in_array($selector, $sources[$key], true)) {
                return $key;
            }
        }

        return 'fallback';
    }

    /**
     * @return list<string>
     */
    private function fromCloudflare(DnsMonitor $monitor): array
    {
        try {
            $site = $monitor->site;
            if (! $site) {
                return [];
            }

            $siteCloudflare = $site->siteCloudflare;
            if (! $siteCloudflare || ! $siteCloudflare->is_active || $siteCloudflare->zone_id === '') {
                return [];
            }

            $connection = $siteCloudflare->cloudflareConnection;
            if (! $connection || ! $connection->is_valid) {
                return [];
            }

            $service = new CloudflareService($connection);

            return $service->discoverDkimSelectors($siteCloudflare->zone_id, $monitor->domain);
        } catch (\Throwable $e) {
            Log::warning("Cloudflare DKIM discovery failed for {$monitor->domain}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function fromPostmark(DnsMonitor $monitor): array
    {
        try {
            if (! $this->postmark->isConfigured()) {
                return [];
            }

            $selector = $this->postmark->getDkimSelectorForDomain($monitor->domain);

            return $selector !== null ? [$selector] : [];
        } catch (\Throwable $e) {
            Log::warning("Postmark DKIM discovery failed for {$monitor->domain}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function normalizeList($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($s) => is_string($s) ? mb_strtolower(trim($s)) : '', $list),
            fn ($s) => $s !== '',
        )));
    }
}
