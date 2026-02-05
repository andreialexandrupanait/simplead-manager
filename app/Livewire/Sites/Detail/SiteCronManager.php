<?php

namespace App\Livewire\Sites\Detail;

use App\Models\Site;
use App\Services\CronManagerService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteCronManager extends Component
{
    public Site $site;

    public array $actionResults = [];

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    #[Computed]
    public function cronJobs()
    {
        return $this->site->siteCronJobs()
            ->orderBy('next_run')
            ->get();
    }

    #[Computed]
    public function overdueCount()
    {
        return $this->site->siteCronJobs()
            ->where('is_disabled', false)
            ->whereNotNull('next_run')
            ->where('next_run', '<', now())
            ->count();
    }

    public function syncCronJobs(): void
    {
        try {
            CronManagerService::sync($this->site);
            session()->flash('cron-success', 'Cron jobs synced successfully.');
        } catch (\Exception $e) {
            session()->flash('cron-error', "Sync failed: {$e->getMessage()}");
        }

        unset($this->cronJobs, $this->overdueCount);
    }

    public function runCron(int $id): void
    {
        $cronJob = $this->site->siteCronJobs()->findOrFail($id);
        $result = CronManagerService::run($this->site, $cronJob);
        $this->actionResults['cron_' . $id] = $result;
        unset($this->cronJobs, $this->overdueCount);
    }

    public function disableCron(int $id): void
    {
        $cronJob = $this->site->siteCronJobs()->findOrFail($id);
        $result = CronManagerService::disable($this->site, $cronJob);
        $this->actionResults['cron_' . $id] = $result;
        unset($this->cronJobs, $this->overdueCount);
    }

    public function enableCron(int $id): void
    {
        $cronJob = $this->site->siteCronJobs()->findOrFail($id);
        $result = CronManagerService::enable($this->site, $cronJob);
        $this->actionResults['cron_' . $id] = $result;
        unset($this->cronJobs, $this->overdueCount);
    }

    public function clearResult(string $key): void
    {
        unset($this->actionResults[$key]);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-cron-manager')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Cron Jobs',
            ]);
    }
}
