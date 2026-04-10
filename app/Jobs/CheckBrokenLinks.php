<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CheckBrokenLinks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    private const MAX_PAGES = 100;

    private const CRAWL_DELAY_MS = 500000; // 500ms in microseconds

    private const USER_AGENT = 'Mozilla/5.0 (compatible; SimpleAdLinkChecker/1.0; +https://manager.simplead.ro)';

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'broken-links-'.$this->site->id;
    }

    public function handle(): void
    {
        $trackerKey = $this->uniqueId();

        JobTracker::start($trackerKey, 'Checking for broken links...');

        $siteUrl = rtrim($this->site->url, '/');
        $host = parse_url($siteUrl, PHP_URL_HOST);

        if (! $host) {
            JobTracker::complete($trackerKey, 'Skipped: invalid site URL');

            return;
        }

        JobTracker::progress($trackerKey, 5, 'Starting crawl from '.$siteUrl);

        $visited = [];
        $queue = [$siteUrl];
        $brokenLinks = [];

        while (! empty($queue) && count($visited) < self::MAX_PAGES) {
            $url = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            usleep(self::CRAWL_DELAY_MS);

            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => false]])
                    ->get($url);

                $statusCode = $response->status();

                if ($statusCode >= 400) {
                    $brokenLinks[] = [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'error' => null,
                    ];
                }

                // Only crawl HTML responses from pages that are up
                if ($statusCode < 400 && $this->isHtmlResponse($response)) {
                    $internalLinks = $this->extractInternalLinks($response->body(), $host, $siteUrl);

                    foreach ($internalLinks as $link) {
                        if (! isset($visited[$link]) && ! in_array($link, $queue, true)) {
                            $queue[] = $link;
                        }
                    }
                }
            } catch (\Exception $e) {
                $brokenLinks[] = [
                    'url' => $url,
                    'status_code' => null,
                    'error' => Str::limit($e->getMessage(), 250),
                ];
            }

            $progress = (int) round((count($visited) / self::MAX_PAGES) * 80) + 10;
            JobTracker::progress($trackerKey, min(90, $progress), 'Crawled '.count($visited).' page(s)...');
        }

        JobTracker::progress($trackerKey, 92, 'Storing results...');

        $this->persistBrokenLinks($brokenLinks);

        $count = count($brokenLinks);
        JobTracker::complete($trackerKey, "Broken link check complete — {$count} issue(s) found");
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail(
            $this->uniqueId(),
            'Broken link check failed: '.($exception?->getMessage() ?? 'Unknown error'),
        );
    }

    private function isHtmlResponse(\Illuminate\Http\Client\Response $response): bool
    {
        $contentType = $response->header('Content-Type') ?? '';

        return str_contains($contentType, 'text/html');
    }

    /**
     * Extract unique internal links from HTML, normalised to absolute URLs.
     *
     * @return string[]
     */
    private function extractInternalLinks(string $html, string $host, string $baseUrl): array
    {
        $links = [];

        preg_match_all('/<a[^>]+href=["\']([^"\'#?]+)["\'][^>]*>/i', $html, $matches);

        foreach ($matches[1] ?? [] as $href) {
            $href = trim($href);

            if (empty($href) || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            // Build absolute URL
            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $absolute = $href;
            } elseif (str_starts_with($href, '//')) {
                $absolute = 'https:'.$href;
            } elseif (str_starts_with($href, '/')) {
                $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
                $absolute = $scheme.'://'.$host.$href;
            } else {
                $absolute = rtrim($baseUrl, '/').'/'.$href;
            }

            // Keep only internal links
            $linkHost = parse_url($absolute, PHP_URL_HOST);

            if ($linkHost === $host) {
                // Strip query strings and fragments for deduplication
                $normalised = strtok($absolute, '?') ?: $absolute;
                $normalised = strtok($normalised, '#') ?: $normalised;
                $links[] = rtrim($normalised, '/');
            }
        }

        return array_unique($links);
    }

    /**
     * @param  array<int, array{url: string, status_code: int|null, error: string|null}>  $brokenLinks
     */
    private function persistBrokenLinks(array $brokenLinks): void
    {
        // Remove stale broken-link issues for this site
        SeoIssue::where('site_id', $this->site->id)
            ->where('category', SeoIssueCategory::Links->value)
            ->whereNull('seo_audit_id')
            ->delete();

        foreach ($brokenLinks as $link) {
            $statusCode = $link['status_code'];
            $error = $link['error'];

            if ($statusCode !== null) {
                $title = "Broken link: HTTP {$statusCode}";
                $description = "URL returned a {$statusCode} status code.";
            } else {
                $title = 'Broken link: connection error';
                $description = $error ?? 'Could not connect to URL.';
            }

            SeoIssue::create([
                'site_id' => $this->site->id,
                'seo_audit_id' => null,
                'category' => SeoIssueCategory::Links->value,
                'severity' => SeoIssueSeverity::High->value,
                'title' => $title,
                'description' => $description,
                'url' => $link['url'],
                'recommendation' => 'Update or remove the broken link to improve user experience and SEO.',
                'meta' => array_filter([
                    'status_code' => $statusCode,
                    'error' => $error,
                ]),
            ]);
        }
    }
}
