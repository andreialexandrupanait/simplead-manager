<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RunSeoAudit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public Site $site, public SeoAudit $audit)
    {
        $this->onQueue('performance');
    }

    public function uniqueId(): string
    {
        return 'seo-audit-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Initializing SEO audit...');
        try {
            $r = Http::timeout(15)->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])->get(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/analysis');
            if ($r->successful()) {
                $d = $r->json('data', []);
                $u = [];
                if (isset($d['seo_plugin'])) {
                    $u['seo_plugin'] = $d['seo_plugin']['name'] ?? null;
                    $u['seo_plugin_version'] = $d['seo_plugin']['version'] ?? null;
                }
                $auditData = $this->audit->data ?? [];
                if (isset($d['search_visibility'])) {
                    $auditData['search_visibility'] = $d['search_visibility'];
                }
                if (isset($d['redirects'])) {
                    $u['redirect_info'] = $d['redirects'];
                }
                $u['data'] = $auditData;
                if (! empty($u)) {
                    $this->audit->update($u);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('SEO: connector fetch failed', ['error' => $e->getMessage()]);
        }
        Bus::chain([new CrawlSitePages($this->site, $this->audit), new AnalyzeSeoPages($this->site, $this->audit), new CalculateSeoScores($this->site, $this->audit)])->onQueue('performance')->dispatch();
    }

    public function failed(?\Throwable $e): void
    {
        $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage());
        CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'SEO audit failed');
        JobTracker::fail($this->uniqueId(), 'SEO audit failed');
    }
}
