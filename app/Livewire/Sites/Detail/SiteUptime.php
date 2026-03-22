<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckUptime;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class SiteUptime extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    protected function jobTrackingKeys(): array
    {
        $monitorId = $this->site->uptimeMonitor?->id;

        return $monitorId ? ['uptime' => 'check-uptime-'.$monitorId] : [];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
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
        if (! $this->monitor) {
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
            $rateLimitKey = "uptime-check:{$this->site->id}:".auth()->id();
            if (! RateLimiter::attempt($rateLimitKey, 10, fn () => true, 3600)) {
                session()->flash('error', 'Too many uptime check requests. Please wait before trying again.');

                return;
            }

            $this->dispatchTrackedJob('uptime', new CheckUptime($this->monitor), 'Checking uptime...');
        }
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->monitor, $this->checkCount, $this->incidents);
        $this->site->refresh();
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
                'title' => $this->site->name.' — Uptime',
            ]);
    }
}
