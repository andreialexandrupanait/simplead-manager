<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSecurity extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    #[Computed]
    public function sslCertificate()
    {
        return $this->site->sslCertificate;
    }

    #[Computed]
    public function domainMonitor()
    {
        return $this->site->domainMonitor;
    }

    #[Computed]
    public function sslHistory()
    {
        if (!$this->site->sslCertificate) {
            return collect();
        }

        return $this->site->sslCertificate
            ->history()
            ->orderByDesc('checked_at')
            ->limit(50)
            ->get();
    }

    public function checkSslNow(): void
    {
        if ($this->site->sslCertificate) {
            CheckSslCertificate::dispatch($this->site->sslCertificate);
            $this->site->sslCertificate->update(['last_checked_at' => now()]);
        }

        unset($this->sslCertificate, $this->sslHistory);
    }

    public function checkDomainNow(): void
    {
        if ($this->site->domainMonitor) {
            CheckDomainExpiry::dispatch($this->site->domainMonitor);
            $this->site->domainMonitor->update(['last_checked_at' => now()]);
        }

        unset($this->domainMonitor);
    }

    public function updateSslAlertSettings(bool $alertsEnabled, int $warnDays): void
    {
        if ($this->site->sslCertificate) {
            $this->site->sslCertificate->update([
                'alerts_enabled' => $alertsEnabled,
                'warn_days' => $warnDays,
            ]);
        }

        unset($this->sslCertificate);
    }

    public function updateDomainAlertSettings(bool $alertsEnabled, int $warnDays): void
    {
        if ($this->site->domainMonitor) {
            $this->site->domainMonitor->update([
                'alerts_enabled' => $alertsEnabled,
                'warn_days' => $warnDays,
            ]);
        }

        unset($this->domainMonitor);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-security')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Security',
            ]);
    }
}
