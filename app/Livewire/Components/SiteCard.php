<?php

namespace App\Livewire\Components;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Livewire\Traits\WithJobTracking;
use App\Models\Site;
use Livewire\Component;

class SiteCard extends Component
{
    use WithJobTracking;

    public Site $site;

    protected function jobTrackingKeys(): array
    {
        $keys = ['backup' => 'backup-' . $this->site->id];
        if ($this->site->uptimeMonitor) {
            $keys['uptime'] = 'check-uptime-' . $this->site->uptimeMonitor->id;
        }
        return $keys;
    }

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->initJobTracking();
    }

    public function runBackup(): void
    {
        $this->dispatchTrackedJob('backup', new CreateBackup($this->site, 'full', 'manual'), 'Creating backup...');
    }

    public function checkNow(): void
    {
        if ($this->site->uptimeMonitor) {
            $this->dispatchTrackedJob('uptime', new CheckUptime($this->site->uptimeMonitor), 'Checking uptime...');
        }
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        $this->site->refresh();
    }

    public function deleteSite(): void
    {
        $name = $this->site->name;
        $this->site->delete();
        $this->dispatch('notify', type: 'success', message: "Site \"{$name}\" has been removed.");
        $this->dispatch('site-deleted');
    }

    public function render()
    {
        return view('livewire.components.site-card');
    }
}
