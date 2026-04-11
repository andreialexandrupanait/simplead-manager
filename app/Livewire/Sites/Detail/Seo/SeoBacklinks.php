<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Backlink;
use App\Models\Site;
use App\Services\BacklinkService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class SeoBacklinks extends Component
{
    use WithFileUploads, WithJobTracking, WithPagination, WithSiteAuthorization;

    public Site $site;

    #[Url]
    public string $spamFilter = 'all';

    #[Url]
    public string $typeFilter = 'all';

    #[Url]
    public string $activeSection = 'backlinks';

    #[Validate('required|file|mimes:csv,txt|max:10240')]
    public $csvFile = null;

    public bool $showImportForm = false;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-backlinks-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    // ── Stats ──

    #[Computed]
    public function stats(): array
    {
        return app(BacklinkService::class)->getStats($this->site);
    }

    #[Computed]
    public function spamDistribution(): array
    {
        return [
            'clean' => Backlink::where('site_id', $this->site->id)->active()->clean()->count(),
            'suspicious' => Backlink::where('site_id', $this->site->id)->active()->suspicious()->count(),
            'toxic' => Backlink::where('site_id', $this->site->id)->active()->toxic()->count(),
        ];
    }

    #[Computed]
    public function anchorTextTypes(): array
    {
        $types = Backlink::where('site_id', $this->site->id)
            ->active()
            ->whereNotNull('anchor_type')
            ->selectRaw("anchor_type, count(*) as count")
            ->groupBy('anchor_type')
            ->pluck('count', 'anchor_type')
            ->all();

        $total = array_sum($types);
        if ($total === 0) {
            return [];
        }

        $labels = [
            'brand' => 'Brand',
            'exact_match' => 'Exact Match',
            'partial_match' => 'Partial Match',
            'generic' => 'Generic',
            'url' => 'URL',
            'image' => 'Image',
            'other' => 'Other',
        ];

        $result = [];
        foreach ($types as $type => $count) {
            $result[] = [
                'type' => $type,
                'label' => $labels[$type] ?? ucfirst($type),
                'count' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }

        usort($result, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    #[Computed]
    public function hasSearchConsole(): bool
    {
        $connection = $this->site->searchConsoleConnection;

        return $connection !== null && $connection->is_active;
    }

    #[Computed]
    public function lastSyncAt(): ?string
    {
        $latest = Backlink::where('site_id', $this->site->id)
            ->whereNotNull('last_verified_at')
            ->max('last_verified_at');

        return $latest ? \Carbon\Carbon::parse($latest)->diffForHumans() : null;
    }

    // ── Backlinks List (paginated) ──

    #[Computed]
    public function backlinksList()
    {
        $query = Backlink::where('site_id', $this->site->id)->active();

        if ($this->spamFilter === 'clean') {
            $query->clean();
        } elseif ($this->spamFilter === 'suspicious') {
            $query->suspicious();
        } elseif ($this->spamFilter === 'toxic') {
            $query->toxic();
        }

        if ($this->typeFilter === 'dofollow') {
            $query->where('is_nofollow', false);
        } elseif ($this->typeFilter === 'nofollow') {
            $query->where('is_nofollow', true);
        }

        return $query->orderByDesc('last_seen_at')->paginate(25);
    }

    // ── Referring Domains ──

    #[Computed]
    public function referringDomains(): array
    {
        return Backlink::where('site_id', $this->site->id)
            ->active()
            ->where('source_domain', '!=', 'gsc-aggregate')
            ->selectRaw("source_domain, count(*) as link_count, avg(spam_score) as avg_spam, max(last_seen_at) as last_seen")
            ->groupBy('source_domain')
            ->orderByDesc('link_count')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'domain' => $row->source_domain,
                'link_count' => $row->link_count,
                'avg_spam' => $row->avg_spam !== null ? round($row->avg_spam) : 0,
                'last_seen' => $row->last_seen,
            ])
            ->all();
    }

    // ── Top Linked Pages ──

    #[Computed]
    public function topLinkedPages(): array
    {
        return app(BacklinkService::class)->getTopLinkedPages($this->site, 20);
    }

    // ── Actions ──

    public function syncAll(): void
    {
        $this->dispatchTrackedJob(
            'sync',
            new \App\Jobs\SyncBacklinks($this->site),
            'Syncing backlinks (GSC + crawl + verify)...',
        );
    }

    public function importCsv(): void
    {
        $this->validate();

        $path = $this->csvFile->getRealPath();
        $imported = app(BacklinkService::class)->importFromCsv($this->site, $path);
        app(BacklinkService::class)->createSnapshot($this->site);

        $this->csvFile = null;
        $this->showImportForm = false;
        $this->invalidateCaches();

        Session::flash('success', __(':count backlinks imported successfully.', ['count' => $imported]));
    }

    public function toggleImportForm(): void
    {
        $this->showImportForm = ! $this->showImportForm;
        if (! $this->showImportForm) {
            $this->csvFile = null;
        }
    }

    public function setSection(string $section): void
    {
        if (in_array($section, ['backlinks', 'domains', 'pages'], true)) {
            $this->activeSection = $section;
        }
    }

    public function updatedSpamFilter(): void
    {
        $this->resetPage();
        unset($this->backlinksList);
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        unset($this->backlinksList);
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        $this->invalidateCaches();
    }

    private function invalidateCaches(): void
    {
        unset($this->stats, $this->spamDistribution, $this->anchorTextTypes, $this->backlinksList, $this->referringDomains, $this->topLinkedPages, $this->lastSyncAt);
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
