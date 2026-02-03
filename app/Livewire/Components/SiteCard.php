<?php

namespace App\Livewire\Components;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Models\Site;
use Livewire\Component;

class SiteCard extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function runBackup(): void
    {
        CreateBackup::dispatch($this->site, 'full', 'manual');
        session()->flash('message', "Backup queued for {$this->site->name}.");
    }

    public function checkNow(): void
    {
        if ($this->site->uptimeMonitor) {
            CheckUptime::dispatch($this->site->uptimeMonitor);
            session()->flash('message', "Uptime check queued for {$this->site->name}.");
        }
    }

    public function render()
    {
        return view('livewire.components.site-card');
    }
}
