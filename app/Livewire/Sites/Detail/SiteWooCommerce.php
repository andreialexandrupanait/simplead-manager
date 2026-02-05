<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWooCommerceStats;
use App\Models\Site;
use App\Services\WooCommerceService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteWooCommerce extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        abort_unless($site->has_woocommerce, 404);
        $this->site = $site;
    }

    #[Computed]
    public function todayStats()
    {
        return app(WooCommerceService::class)->getTodayStats($this->site);
    }

    #[Computed]
    public function revenueChart()
    {
        return app(WooCommerceService::class)->getRevenueChart($this->site, 30);
    }

    #[Computed]
    public function alerts()
    {
        return $this->site->wooCommerceAlerts()
            ->unacknowledged()
            ->orderByDesc('created_at')
            ->get();
    }

    public function syncNow(): void
    {
        SyncWooCommerceStats::dispatch($this->site);
        session()->flash('success', 'WooCommerce sync dispatched.');
        unset($this->todayStats, $this->revenueChart, $this->alerts);
    }

    public function acknowledgeAlert(int $id): void
    {
        $this->site->wooCommerceAlerts()
            ->where('id', $id)
            ->update(['is_acknowledged' => true]);

        unset($this->alerts);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-woocommerce')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — WooCommerce',
            ]);
    }
}
