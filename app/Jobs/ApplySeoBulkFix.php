<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SeoAudit;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P2-21: apply one bulk SEO fix (a single issue-title) to ONE site out of band.
 *
 * The previous implementation pushed every fix to the live client site
 * synchronously inside the Livewire request — outbound connector calls in-band,
 * slow, gateway-timeout prone, and a partial failure aborted the remaining
 * pages. The component now dispatches one of these per site and returns
 * immediately. A failure is logged in failed() and isolated to its own site.
 *
 * Safety rules preserved from the original (audit E-10/E-34): never push a
 * scraped-empty value over real WP content, and always talk to the connector
 * through the signed HMAC client (WordPressApiServiceFactory::make).
 */
class ApplySeoBulkFix implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30, 60];

    public int $timeout = 300;

    public function __construct(
        public Site $site,
        public SeoAudit $audit,
        public string $issueTitle,
        public string $fixType,
        public User $user,
    ) {
        $this->onQueue('default');
    }

    public function handle(WordPressApiServiceFactory $factory): void
    {
        // Authorize inside the job too (defense in depth): the acting user's
        // site access can change between the Livewire dispatch and execution.
        if (! $this->user->canAccessSite($this->site)) {
            Log::warning('ApplySeoBulkFix: unauthorized', [
                'user_id' => $this->user->id,
                'site_id' => $this->site->id,
            ]);

            return;
        }

        if (! $this->site->is_connected || ($this->site->is_prospect ?? false)) {
            return;
        }

        $issues = $this->audit->issues()->where('title', $this->issueTitle)->whereNotNull('url')->get();
        $api = $factory->make($this->site);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $applied = [];

        foreach ($issues as $issue) {
            $page = $this->audit->pages()->where('url', $issue->url)->first();
            if (! $page) {
                $failed++;

                continue;
            }

            // Never push scraped-empty values — that would blank real content
            // on the WP side (e.g. overwrite post_title with '') (audit E-10).
            [$payload, $endpoint] = match ($this->fixType) {
                'meta' => [array_filter([
                    'meta_title' => $page->title,
                    'meta_description' => $page->meta_description,
                ], fn (?string $v): bool => $v !== null && $v !== ''), '/seo/update-meta'],
                'canonical' => [['canonical_url' => $issue->url], '/seo/update-canonical'],
                'og' => [array_filter([
                    'og_title' => $page->title,
                    'og_description' => $page->meta_description,
                ], fn (?string $v): bool => $v !== null && $v !== ''), '/seo/update-og'],
                default => [[], null],
            };

            if ($payload === [] || $endpoint === null) {
                $skipped++;

                continue;
            }

            $payload['url'] = $issue->url;

            try {
                // Signed HMAC client — a raw X-SAM-API-Key header 401s on every
                // connector request (audit E-34/P1-03).
                $response = $api->request('POST', $endpoint, $payload, timeout: 15);

                if ($response->successful()) {
                    $success++;
                    $applied[] = ['url' => $issue->url, 'endpoint' => $endpoint, 'payload' => $payload];
                } else {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
            }

            usleep(200_000); // 200ms between requests
        }

        if ($applied !== []) {
            ActivityLogger::log(
                type: 'seo',
                severity: 'info',
                title: "SEO bulk fix \"{$this->issueTitle}\": {$success} applied on {$this->site->name}",
                description: $skipped > 0 ? "{$skipped} pages skipped (no safe value to write)." : null,
                site: $this->site,
                metadata: ['issue' => $this->issueTitle, 'changes' => $applied],
            );
        }

        Log::info('ApplySeoBulkFix complete', [
            'site_id' => $this->site->id,
            'issue' => $this->issueTitle,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ApplySeoBulkFix failed', [
            'site_id' => $this->site->id,
            'issue' => $this->issueTitle,
            'error' => $e->getMessage(),
        ]);
    }
}
