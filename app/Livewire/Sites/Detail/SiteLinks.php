<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\RunLinkScan;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Link;
use App\Models\LinkMonitor;
use App\Models\LinkScan;
use App\Models\Site;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SiteLinks extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;
    public string $statusFilter = 'all';
    public string $search = '';
    public string $typeFilter = 'all';
    public bool $isScanning = false;

    // Settings form
    public bool $showSettings = false;
    public string $settingsFrequency = 'weekly';
    public string $settingsScanTime = '02:00';
    public ?int $settingsDayOfWeek = 0;
    public int $settingsMaxPages = 200;
    public int $settingsMaxDepth = 5;
    public bool $settingsCheckExternal = true;
    public bool $settingsCheckImages = true;
    public int $settingsTimeout = 30;
    public string $settingsExcludePaths = '';
    public string $settingsExcludeDomains = '';
    public bool $settingsAlertOnBroken = true;
    public int $settingsAlertThreshold = 1;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadSettings();

        // Check if there's an active scan on page load
        $monitor = $site->linkMonitor;
        if ($monitor) {
            $hasRunning = $monitor->scans()
                ->whereIn('status', ['pending', 'in_progress'])
                ->exists();
            if ($hasRunning) {
                $this->isScanning = true;
            }
        }
    }

    #[Computed]
    public function monitor(): ?LinkMonitor
    {
        return $this->site->linkMonitor;
    }

    #[Computed]
    public function latestScan(): ?LinkScan
    {
        return $this->monitor?->latestCompletedScan;
    }

    #[Computed]
    public function activeScan(): ?LinkScan
    {
        if (!$this->monitor) {
            return null;
        }

        return $this->monitor->scans()
            ->whereIn('status', ['pending', 'in_progress'])
            ->latest()
            ->first();
    }

    #[Computed]
    public function links()
    {
        $scan = $this->latestScan;
        if (!$scan) {
            return new LengthAwarePaginator([], 0, 50);
        }

        $query = Link::where('link_scan_id', $scan->id)
            ->where('is_dismissed', false);

        // Status filter
        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        // Type filter
        if ($this->typeFilter !== 'all') {
            $query->where('type', $this->typeFilter);
        }

        // Search
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('url', 'like', "%{$search}%")
                    ->orWhere('anchor_text', 'like', "%{$search}%")
                    ->orWhere('source_url', 'like', "%{$search}%");
            });
        }

        return $query->orderByRaw("CASE status WHEN 'broken' THEN 1 WHEN 'ssl_error' THEN 2 WHEN 'dns_error' THEN 3 WHEN 'timeout' THEN 4 WHEN 'redirect' THEN 5 WHEN 'ok' THEN 6 WHEN 'pending' THEN 7 ELSE 8 END")
            ->paginate(50);
    }

    #[Computed]
    public function scanHistory()
    {
        if (!$this->monitor) {
            return collect();
        }

        return $this->monitor->scans()
            ->whereIn('status', ['completed', 'failed'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $scan = $this->latestScan;
        if (!$scan) {
            return [
                'total' => 0,
                'broken' => 0,
                'redirects' => 0,
                'timeouts' => 0,
                'ok' => 0,
            ];
        }

        return [
            'total' => $scan->total_links,
            'broken' => $scan->broken_links,
            'redirects' => $scan->redirects,
            'timeouts' => $scan->timeouts,
            'ok' => $scan->total_links - $scan->broken_links - $scan->redirects - $scan->timeouts,
        ];
    }

    public function scanNow(): void
    {
        $monitor = $this->monitor;

        if (!$monitor) {
            $monitor = LinkMonitor::create([
                'site_id' => $this->site->id,
                'is_active' => true,
                'frequency' => $this->settingsFrequency,
                'scan_time' => $this->settingsScanTime,
                'day_of_week' => $this->settingsDayOfWeek,
            ]);
            unset($this->monitor);
        }

        // Don't start if already scanning
        if ($monitor->scans()->whereIn('status', ['pending', 'in_progress'])->exists()) {
            return;
        }

        $this->isScanning = true;
        RunLinkScan::dispatch($monitor, 'manual');

        session()->flash('message', 'Link scan queued. Results will appear shortly.');
    }

    public function checkScanProgress(): void
    {
        unset($this->activeScan);
        unset($this->latestScan);
        unset($this->monitor);
        unset($this->links);
        unset($this->stats);
        unset($this->scanHistory);

        if (!$this->activeScan && $this->latestScan) {
            $this->isScanning = false;
        }
    }

    public function dismissLink(int $linkId): void
    {
        $link = Link::where('site_id', $this->site->id)->find($linkId);
        if ($link) {
            $link->update([
                'is_dismissed' => true,
                'dismissed_reason' => 'Manually dismissed',
            ]);
            unset($this->links);
        }
    }

    public function undismissLink(int $linkId): void
    {
        $link = Link::where('site_id', $this->site->id)->find($linkId);
        if ($link) {
            $link->update([
                'is_dismissed' => false,
                'dismissed_reason' => null,
            ]);
            unset($this->links);
        }
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = $filter;
        $this->resetPage();
        unset($this->links);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->links);
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        unset($this->links);
    }

    public function openSettings(): void
    {
        $this->loadSettings();
        $this->dispatch('open-modal-link-settings');
    }

    public function saveSettings(): void
    {
        $monitor = $this->monitor;
        if (!$monitor) {
            return;
        }

        $this->validate([
            'settingsFrequency' => 'required|in:daily,weekly,monthly',
            'settingsScanTime' => 'required|date_format:H:i',
            'settingsMaxPages' => 'required|integer|min:1|max:5000',
            'settingsMaxDepth' => 'required|integer|min:1|max:50',
            'settingsTimeout' => 'required|integer|min:5|max:120',
            'settingsAlertThreshold' => 'required|integer|min:1|max:1000',
        ]);

        $excludePaths = array_filter(array_map('trim', explode("\n", $this->settingsExcludePaths)));
        $excludeDomains = array_filter(array_map('trim', explode("\n", $this->settingsExcludeDomains)));

        $monitor->update([
            'frequency' => $this->settingsFrequency,
            'scan_time' => $this->settingsScanTime,
            'day_of_week' => $this->settingsFrequency === 'weekly' ? $this->settingsDayOfWeek : null,
            'max_pages' => $this->settingsMaxPages,
            'max_depth' => $this->settingsMaxDepth,
            'check_external' => $this->settingsCheckExternal,
            'check_images' => $this->settingsCheckImages,
            'timeout_seconds' => $this->settingsTimeout,
            'exclude_paths' => !empty($excludePaths) ? $excludePaths : null,
            'exclude_domains' => !empty($excludeDomains) ? $excludeDomains : null,
            'alert_on_broken' => $this->settingsAlertOnBroken,
            'alert_threshold' => $this->settingsAlertThreshold,
        ]);

        unset($this->monitor);
        $this->dispatch('close-modal-link-settings');
        session()->flash('message', 'Link checker settings updated.');
    }

    private function loadSettings(): void
    {
        $monitor = $this->site->linkMonitor;
        if ($monitor) {
            $this->settingsFrequency = $monitor->frequency;
            $this->settingsScanTime = $monitor->scan_time;
            $this->settingsDayOfWeek = $monitor->day_of_week;
            $this->settingsMaxPages = $monitor->max_pages;
            $this->settingsMaxDepth = $monitor->max_depth;
            $this->settingsCheckExternal = $monitor->check_external;
            $this->settingsCheckImages = $monitor->check_images;
            $this->settingsTimeout = $monitor->timeout_seconds;
            $this->settingsExcludePaths = implode("\n", $monitor->exclude_paths ?? []);
            $this->settingsExcludeDomains = implode("\n", $monitor->exclude_domains ?? []);
            $this->settingsAlertOnBroken = $monitor->alert_on_broken;
            $this->settingsAlertThreshold = $monitor->alert_threshold;
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.site-links')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Links',
            ]);
    }
}
