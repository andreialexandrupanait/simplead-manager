<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportSectionGathererInterface;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\Reports\Sections\ExecutiveSnapshotGatherer;
use Carbon\Carbon;

class ReportDataGatherer
{
    protected array $data = [];

    protected array $excludedSections = [];

    /**
     * @param  ReportSectionGathererInterface[]  $gatherers
     */
    public function __construct(
        protected Site $site,
        protected ReportTemplate $template,
        protected Carbon $periodStart,
        protected Carbon $periodEnd,
        protected ?SiteMonthlySnapshot $currentSnapshot,
        protected ?SiteMonthlySnapshot $previousSnapshot,
        protected ReportChartService $chartService,
        protected string $language,
        protected array $gatherers = [],
    ) {}

    public function gather(array $excludedSections): array
    {
        $this->excludedSections = $excludedSections;
        $sections = array_diff($this->template->sections ?? [], $excludedSections);

        $sectionKeys = [
            'overview',
            'updates',
            'uptime',
            'backups',
            'analytics',
            'search_console',
            'performance',
            'database',
            'security',
            'plugin_inventory',
            'database_health',
            'cloudflare',
            'wp_users',
            'security_checks',
            'seo',
        ];

        foreach ($sectionKeys as $key) {
            if (! in_array($key, $sections)) {
                continue;
            }

            $gatherer = $this->findGatherer($key);
            if ($gatherer === null) {
                continue;
            }

            $result = $gatherer->gather(
                $this->site,
                $this->periodStart,
                $this->periodEnd,
                $this->currentSnapshot,
                $this->previousSnapshot,
                $this->chartService,
                $this->language,
            );

            // Preserve null semantics for sections that return empty arrays to signal "no data"
            $this->data[$key] = $result ?: null;
        }

        // Always gather email data (shown inside technical stability)
        $emailGatherer = $this->findGatherer('email');
        if ($emailGatherer !== null) {
            $result = $emailGatherer->gather(
                $this->site,
                $this->periodStart,
                $this->periodEnd,
                $this->currentSnapshot,
                $this->previousSnapshot,
                $this->chartService,
                $this->language,
            );
            $this->data['email'] = $result ?: null;
        }

        // Executive snapshot (aggregates already-gathered data; only when overview is included)
        if (in_array('overview', $sections)) {
            $snapshotGatherer = $this->findGatherer('executive_snapshot');
            if ($snapshotGatherer instanceof ExecutiveSnapshotGatherer) {
                $this->data['executive_snapshot'] = $snapshotGatherer
                    ->withData($this->data)
                    ->withExcludedSections($this->excludedSections)
                    ->withTemplate($this->template)
                    ->gather(
                        $this->site,
                        $this->periodStart,
                        $this->periodEnd,
                        $this->currentSnapshot,
                        $this->previousSnapshot,
                        $this->chartService,
                        $this->language,
                    );
            }
        }

        // Recommendations (rule-based, derived from all gathered data)
        $recService = new ReportRecommendationService($this->data, $this->language);
        $this->data['recommendations'] = $recService->generate();

        // Check for approved DB recommendations (from the approval UI)
        $hasDrafts = \App\Models\ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->exists();

        if ($hasDrafts) {
            $approvedRecs = \App\Models\ReportRecommendation::where('site_id', $this->site->id)
                ->whereNull('report_id')
                ->where('is_included', true)
                ->orderBy('sort_order')
                ->get();

            $this->data['recommendations_approved'] = $approvedRecs;
        }

        $this->data['_meta'] = [
            'sections' => array_values(array_diff($this->template->sections ?? [], $this->excludedSections)),
            'section_options' => $this->template->section_options ?? [],
        ];

        return $this->data;
    }

    private function findGatherer(string $key): ?ReportSectionGathererInterface
    {
        foreach ($this->gatherers as $gatherer) {
            if ($gatherer->supports($key)) {
                return $gatherer;
            }
        }

        return null;
    }
}
