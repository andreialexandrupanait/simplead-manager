<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SeoAudit;
use App\Models\SeoImage;
use App\Models\SeoLink;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckBrokenResources implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public Site $site, public SeoAudit $audit)
    {
        $this->onQueue('performance');
    }

    public function uniqueId(): string
    {
        return 'broken-resources-'.$this->site->id;
    }

    public function handle(): void
    {
        $userAgent = config('seo.crawler.user_agent');
        $maxLinkChecks = (int) config('seo.analysis.max_external_link_checks', 50);
        $maxImageChecks = (int) config('seo.analysis.max_image_checks', 100);

        // Re-check external broken links
        $this->recheckLinks($userAgent, $maxLinkChecks);

        // Re-check broken images
        $this->recheckImages($userAgent, $maxImageChecks);

        // Update denormalized counts
        $this->audit->update([
            'broken_links_count' => $this->audit->links()->where('is_broken', true)->count(),
            'broken_images_count' => $this->audit->images()->where('is_broken', true)->count(),
        ]);

        Log::info('Broken resources check complete', [
            'site_id' => $this->site->id,
            'audit_id' => $this->audit->id,
        ]);
    }

    private function recheckLinks(string $userAgent, int $maxChecks): void
    {
        $externalUrls = SeoLink::where('seo_audit_id', $this->audit->id)
            ->where('type', 'external')
            ->selectRaw('MIN(target_url) as target_url, target_url_hash')
            ->groupBy('target_url_hash')
            ->limit($maxChecks)
            ->get();

        foreach ($externalUrls as $link) {
            try {
                $response = Http::timeout(5)->withUserAgent($userAgent)->withoutVerifying()->head($link->target_url);
                $status = $response->status();

                if ($status === 405) {
                    $response = Http::timeout(5)->withUserAgent($userAgent)->withoutVerifying()->get($link->target_url);
                    $status = $response->status();
                }
            } catch (\Throwable) {
                $status = null;
            }

            $isBroken = $status === null || $status >= 400;
            SeoLink::where('seo_audit_id', $this->audit->id)
                ->where('target_url_hash', $link->target_url_hash)
                ->update(['status_code' => $status, 'is_broken' => $isBroken]);

            usleep(100_000);
        }
    }

    private function recheckImages(string $userAgent, int $maxChecks): void
    {
        $imageUrls = SeoImage::where('seo_audit_id', $this->audit->id)
            ->selectRaw('MIN(image_url) as image_url, image_url_hash')
            ->groupBy('image_url_hash')
            ->limit($maxChecks)
            ->get();

        foreach ($imageUrls as $image) {
            try {
                $response = Http::timeout(5)->withUserAgent($userAgent)->withoutVerifying()->head($image->image_url);
                $status = $response->status();
            } catch (\Throwable) {
                $status = null;
            }

            $isBroken = $status === null || $status >= 400;
            SeoImage::where('seo_audit_id', $this->audit->id)
                ->where('image_url_hash', $image->image_url_hash)
                ->update(['status_code' => $status, 'is_broken' => $isBroken]);

            usleep(50_000);
        }
    }
}
