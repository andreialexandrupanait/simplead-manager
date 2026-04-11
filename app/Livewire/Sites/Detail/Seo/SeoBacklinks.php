<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Backlink;
use App\Models\Site;
use App\Models\SiteCrawl;
use App\Services\BacklinkService;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class SeoBacklinks extends Component
{
    use WithFileUploads, WithSiteAuthorization;

    public Site $site;

    #[Validate('required|file|mimes:csv,txt|max:10240')]
    public $csvFile = null;

    public bool $showImportForm = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function stats(): array
    {
        return app(BacklinkService::class)->getStats($this->site);
    }

    #[Computed]
    public function topLinkedPages(): array
    {
        return app(BacklinkService::class)->getTopLinkedPages($this->site, 20);
    }

    #[Computed]
    public function anchorDistribution(): array
    {
        return app(BacklinkService::class)->getAnchorDistribution($this->site, 30);
    }

    #[Computed]
    public function hasSearchConsole(): bool
    {
        $connection = $this->site->searchConsoleConnection;

        return $connection !== null && $connection->is_active;
    }

    #[Computed]
    public function hasCrawlData(): bool
    {
        return SiteCrawl::where('site_id', $this->site->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->exists();
    }

    #[Computed]
    public function lastSyncAt(): ?string
    {
        $latest = Backlink::where('site_id', $this->site->id)
            ->latest('last_seen_at')
            ->value('last_seen_at');

        return $latest?->diffForHumans();
    }

    #[Computed]
    public function spamCount(): int
    {
        return Backlink::where('site_id', $this->site->id)
            ->active()
            ->where('spam_score', '>=', 40)
            ->count();
    }

    public function syncFromGsc(): void
    {
        $service = app(BacklinkService::class);
        $synced = $service->syncFromGsc($this->site);
        $service->createSnapshot($this->site);

        $this->invalidateComputed();

        Session::flash('success', __(':count backlinks synced from Google Search Console.', ['count' => $synced]));
    }

    public function discoverFromCrawl(): void
    {
        $service = app(BacklinkService::class);
        $discovered = $service->discoverFromCrawl($this->site);
        $service->recalculateSpamScores($this->site);
        $service->createSnapshot($this->site);

        $this->invalidateComputed();

        Session::flash('success', __(':count backlinks discovered from crawl data.', ['count' => $discovered]));
    }

    public function importCsv(): void
    {
        $this->validate();

        $path = $this->csvFile->getRealPath();
        $imported = app(BacklinkService::class)->importFromCsv($this->site, $path);

        $this->csvFile = null;
        $this->showImportForm = false;

        app(BacklinkService::class)->createSnapshot($this->site);
        $this->invalidateComputed();

        Session::flash('success', __(':count backlinks imported successfully.', ['count' => $imported]));
    }

    public function toggleImportForm(): void
    {
        $this->showImportForm = ! $this->showImportForm;

        if (! $this->showImportForm) {
            $this->csvFile = null;
        }
    }

    private function invalidateComputed(): void
    {
        unset($this->stats, $this->topLinkedPages, $this->anchorDistribution, $this->lastSyncAt, $this->spamCount);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-backlinks')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Backlinks',
            ]);
    }
}
