<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckCoreFileIntegrity;
use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Jobs\RunSecurityScan;
use App\Livewire\Traits\WithJobTracking;
use App\Models\SecurityIssue;
use App\Models\SecurityRecommendation;
use App\Models\Site;
use App\Models\VulnerabilityAlert;
use App\Services\SecurityRecommendationService;
use App\Services\SecurityScanService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSecurity extends Component
{
    use WithJobTracking;

    public Site $site;
    public string $securityTab = 'overview';

    protected function jobTrackingKeys(): array
    {
        return [
            'scan' => 'security-scan-' . $this->site->id,
            'integrity' => 'core-integrity-' . $this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->initJobTracking();
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

    #[Computed]
    public function latestScan()
    {
        return $this->site->latestSecurityScan;
    }

    #[Computed]
    public function activeIssues()
    {
        return SecurityIssue::where('site_id', $this->site->id)
            ->active()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->get();
    }

    #[Computed]
    public function recommendations()
    {
        return SecurityRecommendation::where('site_id', $this->site->id)
            ->orderBy('category')
            ->orderBy('key')
            ->get()
            ->groupBy('category');
    }

    #[Computed]
    public function recommendationStats()
    {
        $recs = SecurityRecommendation::where('site_id', $this->site->id);
        return [
            'passed' => (clone $recs)->where('status', 'passed')->count(),
            'failed' => (clone $recs)->where('status', 'failed')->count(),
            'total' => $recs->count(),
        ];
    }

    #[Computed]
    public function vulnerabilities()
    {
        return VulnerabilityAlert::where('site_id', $this->site->id)
            ->active()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->get();
    }

    #[Computed]
    public function latestCoreCheck()
    {
        return $this->site->latestCoreFileCheck;
    }

    public function scanNow(): void
    {
        $this->dispatchTrackedJob('scan', new RunSecurityScan($this->site), 'Running security scan...');
        unset($this->latestScan, $this->activeIssues, $this->recommendations, $this->recommendationStats, $this->vulnerabilities);
    }

    public function fixRecommendation(string $key): void
    {
        $result = SecurityRecommendationService::fix($this->site, $key);

        if ($result) {
            session()->flash('rec-fixed', 'Security fix applied successfully.');
        } else {
            session()->flash('rec-error', 'Failed to apply security fix.');
        }

        unset($this->recommendations, $this->recommendationStats);
    }

    public function ignoreRecommendation(int $id): void
    {
        $rec = SecurityRecommendation::find($id);
        if ($rec && $rec->site_id === $this->site->id) {
            SecurityRecommendationService::ignore($rec);
        }
        unset($this->recommendations, $this->recommendationStats);
    }

    public function resolveIssue(int $id): void
    {
        $issue = SecurityIssue::find($id);
        if ($issue && $issue->site_id === $this->site->id) {
            SecurityScanService::resolveIssue($issue);
        }
        unset($this->activeIssues, $this->latestScan);
    }

    public function ignoreIssue(int $id): void
    {
        $issue = SecurityIssue::find($id);
        if ($issue && $issue->site_id === $this->site->id) {
            SecurityScanService::ignoreIssue($issue);
        }
        unset($this->activeIssues, $this->latestScan);
    }

    public function ignoreVulnerability(int $id): void
    {
        $vuln = VulnerabilityAlert::find($id);
        if ($vuln && $vuln->site_id === $this->site->id) {
            $vuln->update(['status' => 'ignored']);
        }
        unset($this->vulnerabilities);
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

    public function checkCoreIntegrityNow(): void
    {
        $this->dispatchTrackedJob('integrity', new CheckCoreFileIntegrity($this->site), 'Checking core file integrity...');
        unset($this->latestCoreCheck);
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestScan, $this->activeIssues, $this->recommendations, $this->recommendationStats, $this->vulnerabilities, $this->latestCoreCheck);
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
