<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class SiteUptime extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    #[Computed]
    public function monitor(): ?UptimeMonitor
    {
        return $this->site->uptimeMonitor;
    }

    #[Computed]
    public function checkCount(): int
    {
        return $this->monitor?->checks()->count() ?? 0;
    }

    #[Computed]
    public function incidents()
    {
        if (!$this->monitor) {
            return collect();
        }

        return $this->monitor->incidents()
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();
    }

    public function pauseMonitor(): void
    {
        $this->monitor?->update(['status' => 'paused']);
    }

    public function resumeMonitor(): void
    {
        $this->monitor?->update([
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    public function testNow(): void
    {
        if ($this->monitor) {
            CheckUptime::dispatch($this->monitor);
        }
    }

    #[On('monitor-saved')]
    public function refreshData(): void
    {
        unset($this->monitor);
        $this->site->refresh();
    }

    public function render()
    {
        return view('livewire.sites.detail.site-uptime')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Uptime',
            ]);
    }
}
