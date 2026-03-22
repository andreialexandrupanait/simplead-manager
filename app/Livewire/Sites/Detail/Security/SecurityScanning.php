<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Jobs\CheckCoreFileIntegrity;
use App\Jobs\CheckSslCertificate;
use App\Jobs\RunSecurityScan;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\CoreFileCheck;
use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Models\VulnerabilityAlert;
use App\Services\SecurityScanService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityScanning extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    protected function jobTrackingKeys(): array
    {
        return [
            'scan' => 'security-scan-'.$this->site->id,
            'integrity' => 'core-integrity-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function sslCertificate(): ?SslCertificate
    {
        return $this->site->sslCertificate;
    }

    #[Computed]
    public function latestScan(): ?SecurityScan
    {
        return $this->site->latestSecurityScan;
    }

    #[Computed]
    public function activeIssues(): Collection
    {
        return SecurityIssue::where('site_id', $this->site->id)
            ->active()
            ->orderBySeverity()
            ->get();
    }

    #[Computed]
    public function vulnerabilities(): Collection
    {
        return VulnerabilityAlert::where('site_id', $this->site->id)
            ->active()
            ->orderBySeverity()
            ->get();
    }

    #[Computed]
    public function latestCoreCheck(): ?CoreFileCheck
    {
        return $this->site->latestCoreFileCheck;
    }

    public function scanNow(): void
    {
        $rateLimitKey = "security-scan:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('error', 'Too many scan requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('scan', new RunSecurityScan($this->site), 'Running security scan...');
        unset($this->latestScan, $this->activeIssues, $this->vulnerabilities);
    }

    public function resolveIssue(int $id): void
    {
        $issue = SecurityIssue::find($id);
        if ($issue && $issue->site_id === $this->site->id) {
            app(SecurityScanService::class)->resolveIssue($issue);
        }
        unset($this->activeIssues, $this->latestScan);
    }

    public function ignoreIssue(int $id): void
    {
        $issue = SecurityIssue::find($id);
        if ($issue && $issue->site_id === $this->site->id) {
            app(SecurityScanService::class)->ignoreIssue($issue);
        }
        unset($this->activeIssues, $this->latestScan);
    }

    public function checkSslNow(): void
    {
        $rateLimitKey = "ssl-check:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('error', 'Too many SSL check requests. Please wait before trying again.');

            return;
        }

        if ($this->site->sslCertificate) {
            CheckSslCertificate::dispatch($this->site->sslCertificate);
            $this->site->sslCertificate->update(['last_checked_at' => now()]);
        }

        unset($this->sslCertificate);
    }

    public function checkCoreIntegrityNow(): void
    {
        $rateLimitKey = "integrity-check:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('error', 'Too many integrity check requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('integrity', new CheckCoreFileIntegrity($this->site), 'Checking core file integrity...');
        unset($this->latestCoreCheck);
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestScan, $this->activeIssues, $this->vulnerabilities, $this->latestCoreCheck, $this->sslCertificate);
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-scanning')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Scanning',
            ]);
    }
}
