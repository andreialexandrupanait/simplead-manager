<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Jobs\RunSeoAudit;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\BacklinkSnapshot;
use App\Models\PerformanceTest;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\ContentIntelligenceService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoOverview extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    protected function jobTrackingKeys(): array
    {
        return [
            'audit' => 'seo-audit-'.$this->site->id,
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
        return app(ModuleConfigService::class)->isModuleActive($this->site, 'seo');
    }

    #[Computed]
    public function latestAudit(): ?SeoAudit
    {
        return $this->site->latestSeoAudit;
    }

    #[Computed]
    public function recentAudits(): Collection
    {
        return $this->site->seoAudits()
            ->orderByDesc('scanned_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function keywordsCount(): int
    {
        return $this->site->trackedKeywords()->count();
    }

    #[Computed]
    public function activeIssuesCount(): int
    {
        return $this->site->seoIssues()->whereNull('resolved_at')->count();
    }

    #[Computed]
    public function hasSearchConsole(): bool
    {
        $connection = $this->site->searchConsoleConnection;

        return $connection !== null && $connection->is_active;
    }

    #[Computed]
    public function seoPlugin(): ?string
    {
        $audit = $this->latestAudit;

        if (! $audit || ! $audit->seo_plugin) {
            return null;
        }

        return $audit->seo_plugin.($audit->seo_plugin_version ? ' v'.$audit->seo_plugin_version : '');
    }

    #[Computed]
    public function cwvSummary(): ?array
    {
        $test = PerformanceTest::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        if (! $test) {
            return null;
        }

        return [
            'lcp' => $test->field_lcp ?? $test->lcp,
            'cls' => $test->field_cls ?? $test->cls,
            'inp' => $test->field_inp,
            'performance_score' => $test->performance_score,
        ];
    }

    #[Computed]
    public function backlinkStats(): ?array
    {
        $snapshot = BacklinkSnapshot::where('site_id', $this->site->id)
            ->latest('date')
            ->first();

        if (! $snapshot) {
            return null;
        }

        return [
            'total' => $snapshot->total_backlinks,
            'referring_domains' => $snapshot->referring_domains,
            'new' => $snapshot->new_backlinks,
            'lost' => $snapshot->lost_backlinks,
        ];
    }

    #[Computed]
    public function cannibalizationCount(): int
    {
        return count(app(ContentIntelligenceService::class)->detectCannibalization($this->site));
    }

    #[Computed]
    public function zeroTrafficPagesCount(): int
    {
        return count(app(ContentIntelligenceService::class)->findPagesWithoutTraffic($this->site));
    }

    #[Computed]
    public function auditSchedule(): ?string
    {
        $monitor = $this->site->seoMonitor;
        if (! $monitor || ! $monitor->is_active || ! $monitor->next_audit_at) {
            return null;
        }

        return match ($monitor->interval_minutes) {
            1440 => 'daily',
            10080 => 'weekly',
            43200 => 'monthly',
            default => $monitor->interval_minutes.' min',
        };
    }

    public function updateSchedule(string $interval): void
    {
        $monitor = $this->site->seoMonitor;
        if (! $monitor) {
            return;
        }

        if ($interval === 'off') {
            $monitor->update(['next_audit_at' => null]);
        } else {
            $minutes = match ($interval) {
                'daily' => 1440,
                'weekly' => 10080,
                'monthly' => 43200,
                default => 10080,
            };

            $monitor->update([
                'interval_minutes' => $minutes,
                'next_audit_at' => now()->addMinutes($minutes),
            ]);
        }

        unset($this->auditSchedule);
    }

    public function activateModule(): void
    {
        app(ModuleConfigService::class)->toggleModule($this->site, 'seo', true);
        $this->site->refresh();
        $this->site->load('seoMonitor');
        unset($this->isModuleActive);
    }

    public function runAudit(): void
    {
        $this->dispatchTrackedJob('audit', new RunSeoAudit($this->site), 'Starting SEO audit...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestAudit, $this->recentAudits, $this->activeIssuesCount);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
