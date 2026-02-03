<?php

namespace App\Livewire\Dashboard;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Jobs\GenerateReport;
use App\Jobs\SyncWordPressSite;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\DashboardService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GlobalDashboard extends Component
{
    #[Computed]
    public function stats(): array
    {
        return app(DashboardService::class)->getStats();
    }

    #[Computed]
    public function sites()
    {
        return app(DashboardService::class)->getSitesOverview(0);
    }

    public function runBackup(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        CreateBackup::dispatch($site, 'full', 'manual');
        session()->flash('message', "Backup queued for {$site->name}.");
    }

    public function checkNow(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        if ($site->uptimeMonitor) {
            CheckUptime::dispatch($site->uptimeMonitor);
            session()->flash('message', "Uptime check queued for {$site->name}.");
        }
    }

    public function syncSite(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        SyncWordPressSite::dispatch($site);
        session()->flash('message', "Sync queued for {$site->name}.");
    }

    public function generateQuickReport(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        $template = ReportTemplate::where('is_default', true)->first() ?? ReportTemplate::first();
        if (!$template) {
            session()->flash('message', 'No report template configured.');
            return;
        }
        GenerateReport::dispatch($site, $template, now()->subDays(30)->startOfDay(), now()->endOfDay(), 'manual');
        session()->flash('message', "Report generation queued for {$site->name}.");
    }

    public function render()
    {
        return view('livewire.dashboard.global-dashboard')
            ->layout('components.layouts.app', ['title' => 'Dashboard']);
    }
}
