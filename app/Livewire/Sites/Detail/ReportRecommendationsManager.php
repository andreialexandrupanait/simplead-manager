<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Models\RecommendationTemplate;
use App\Models\ReportRecommendation;
use App\Models\Site;
use App\Services\ReportRecommendationService;
use Livewire\Component;

class ReportRecommendationsManager extends Component
{
    public Site $site;

    // New recommendation form
    public string $newRecTitle = '';

    public string $newRecDescription = '';

    public string $newRecPriority = 'medium';

    public string $newRecCategory = 'technical';

    // Template
    public string $templateName = '';

    public bool $showTemplateModal = false;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function regenerateSuggestions(): void
    {
        // Gather current site data for the recommendation engine
        $reportConfig = $this->site->reportConfig;
        $language = $reportConfig->language ?? 'ro';

        // We need to build a minimal data array for the recommendation service
        // by reading the latest snapshot and monitors
        $data = $this->gatherSiteDataForRecs();

        $recService = new ReportRecommendationService($data, $language);
        $recService->generateAndPersist($this->site);

        $this->dispatch('notify', type: 'success', message: 'Suggestions regenerated.');
    }

    public function toggleIncluded(int $recId): void
    {
        $rec = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->findOrFail($recId);

        $rec->update(['is_included' => ! $rec->is_included]);
    }

    public function updateRec(int $recId, string $field, string $value): void
    {
        $allowed = ['title', 'description', 'priority'];
        if (! in_array($field, $allowed)) {
            return;
        }

        $rec = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->findOrFail($recId);

        $rec->update([$field => $value]);
    }

    public function addCustomRecommendation(): void
    {
        $this->validate([
            'newRecTitle' => 'required|string|max:255',
            'newRecDescription' => 'required|string|max:1000',
            'newRecPriority' => 'required|in:high,medium,low',
            'newRecCategory' => 'required|in:technical,performance,seo',
        ]);

        $maxSort = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->max('sort_order') ?? -1;

        ReportRecommendation::create([
            'site_id' => $this->site->id,
            'category' => $this->newRecCategory,
            'priority' => $this->newRecPriority,
            'title' => $this->newRecTitle,
            'description' => $this->newRecDescription,
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => $maxSort + 1,
        ]);

        $this->newRecTitle = '';
        $this->newRecDescription = '';
        $this->newRecPriority = 'medium';
        $this->newRecCategory = 'technical';

        $this->dispatch('notify', type: 'success', message: 'Recommendation added.');
    }

    public function removeRecommendation(int $recId): void
    {
        ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->where('id', $recId)
            ->delete();

        $this->dispatch('notify', type: 'success', message: 'Recommendation removed.');
    }

    public function moveUp(int $recId): void
    {
        $recs = $this->getDraftRecs();
        $index = $recs->search(fn ($r) => $r->id === $recId);

        if ($index > 0) {
            $prev = $recs[$index - 1];
            $current = $recs[$index];

            $tempSort = $prev->sort_order;
            $prev->update(['sort_order' => $current->sort_order]);
            $current->update(['sort_order' => $tempSort]);
        }
    }

    public function moveDown(int $recId): void
    {
        $recs = $this->getDraftRecs();
        $index = $recs->search(fn ($r) => $r->id === $recId);

        if ($index !== false && $index < $recs->count() - 1) {
            $next = $recs[$index + 1];
            $current = $recs[$index];

            $tempSort = $next->sort_order;
            $next->update(['sort_order' => $current->sort_order]);
            $current->update(['sort_order' => $tempSort]);
        }
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
        ]);

        $recs = $this->getDraftRecs()->map(fn ($r) => [
            'title' => $r->title,
            'description' => $r->description,
            'priority' => $r->priority,
            'category' => $r->category,
        ])->values()->toArray();

        RecommendationTemplate::create([
            'user_id' => auth()->id(),
            'name' => $this->templateName,
            'recommendations' => $recs,
        ]);

        $this->templateName = '';
        $this->showTemplateModal = false;
        $this->dispatch('notify', type: 'success', message: 'Template saved.');
    }

    public function loadTemplate(int $templateId): void
    {
        $template = RecommendationTemplate::findOrFail($templateId);

        // Delete existing drafts
        ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->delete();

        foreach ($template->recommendations as $index => $rec) {
            ReportRecommendation::create([
                'site_id' => $this->site->id,
                'category' => $rec['category'] ?? 'technical',
                'priority' => $rec['priority'] ?? 'medium',
                'title' => $rec['title'] ?? '',
                'description' => $rec['description'] ?? '',
                'is_auto_generated' => false,
                'is_included' => true,
                'sort_order' => $index,
            ]);
        }

        $this->dispatch('notify', type: 'success', message: 'Template loaded.');
    }

    public function deleteTemplate(int $templateId): void
    {
        RecommendationTemplate::where('id', $templateId)
            ->where('user_id', auth()->id())
            ->delete();

        $this->dispatch('notify', type: 'success', message: 'Template deleted.');
    }

    protected function getDraftRecs()
    {
        return ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->orderBy('sort_order')
            ->get();
    }

    protected function gatherSiteDataForRecs(): array
    {
        $data = [];

        // Uptime
        $monitor = $this->site->uptimeMonitor;
        if ($monitor) {
            $data['uptime'] = [
                'uptime_percentage' => $monitor->uptime_30d,
                'avg_response_time' => $monitor->avg_response_time,
                'incidents_count' => $monitor->incidents_count_30d ?? 0,
            ];
        }

        // Backups
        $config = $this->site->backupConfig;
        $data['backups'] = [
            'schedule_enabled' => (bool) ($config?->is_enabled),
            'failed_count' => 0,
        ];

        // Security
        $secMon = $this->site->securityMonitor;
        if ($secMon) {
            $data['security'] = [
                'score' => $secMon->latest_score,
                'critical_count' => 0,
            ];
        }

        // Performance
        $perfMon = $this->site->performanceMonitor;
        if ($perfMon) {
            $mobileTest = $perfMon->latestMobileTest;
            $desktopTest = $perfMon->latestDesktopTest;
            $data['performance'] = [
                'mobile_score' => $mobileTest?->performance_score,
                'desktop_score' => $desktopTest?->performance_score,
                'mobile' => $mobileTest ? [
                    'lcp_color' => $mobileTest->metricColor('lcp'),
                    'cls_color' => $mobileTest->metricColor('cls'),
                    'tbt_color' => $mobileTest->metricColor('tbt'),
                ] : [],
            ];
        }

        // Analytics
        $analyticsCache = $this->site->analyticsCaches()
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if ($analyticsCache) {
            $overview = $analyticsCache->data['overview'] ?? [];
            $data['analytics'] = [
                'bounce_rate' => $overview['bounce_rate'] ?? null,
                'avg_session_duration' => $overview['avg_session_duration'] ?? null,
            ];
        }

        // Search Console
        $scCache = $this->site->searchConsoleCaches()
            ->where('data_type', 'overview')
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if ($scCache) {
            $scData = $scCache->data ?? [];
            $data['search_console'] = [
                'overview' => [
                    'avg_position' => $scData['position'] ?? null,
                    'avg_ctr' => ($scData['ctr'] ?? 0) / 100,
                ],
            ];
        }

        return $data;
    }

    public function render()
    {
        $recommendations = $this->getDraftRecs();
        $templates = RecommendationTemplate::where('user_id', auth()->id())
            ->orderBy('name')
            ->get();

        return view('livewire.sites.detail.report-recommendations-manager', [
            'recommendations' => $recommendations,
            'templates' => $templates,
        ]);
    }
}
