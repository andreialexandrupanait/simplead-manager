<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckCoreFileIntegrity;
use App\Jobs\CheckSslCertificate;
use App\Jobs\RunSecurityScan;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SecurityIssue;
use App\Models\Site;
use App\Models\VulnerabilityAlert;
use App\Services\ModuleConfigService;
use App\Services\SecurityScanService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSecurity extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    protected function jobTrackingKeys(): array
    {
        return [
            'scan' => 'security-scan-' . $this->site->id,
            'integrity' => 'core-integrity-' . $this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function isModuleActive(): bool
    {
        return app(ModuleConfigService::class)->isModuleActive($this->site, 'security');
    }

    public function activateModule(): void
    {
        app(ModuleConfigService::class)->toggleModule($this->site, 'security', true);
        unset($this->isModuleActive);
    }

    #[Computed]
    public function sslCertificate()
    {
        return $this->site->sslCertificate;
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
        unset($this->latestScan, $this->activeIssues, $this->vulnerabilities);
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

    public function checkSslNow(): void
    {
        if ($this->site->sslCertificate) {
            CheckSslCertificate::dispatch($this->site->sslCertificate);
            $this->site->sslCertificate->update(['last_checked_at' => now()]);
        }

        unset($this->sslCertificate, $this->sslHistory);
    }

    public function checkCoreIntegrityNow(): void
    {
        $this->dispatchTrackedJob('integrity', new CheckCoreFileIntegrity($this->site), 'Checking core file integrity...');
        unset($this->latestCoreCheck);
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestScan, $this->activeIssues, $this->vulnerabilities, $this->latestCoreCheck, $this->sslCertificate);
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
