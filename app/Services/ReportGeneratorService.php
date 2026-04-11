<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\Reports\Sections\AnalyticsGatherer;
use App\Services\Reports\Sections\BackupsGatherer;
use App\Services\Reports\Sections\CloudflareGatherer;
use App\Services\Reports\Sections\ContentFreshnessGatherer;
use App\Services\Reports\Sections\DatabaseGatherer;
use App\Services\Reports\Sections\DatabaseHealthGatherer;
use App\Services\Reports\Sections\DnsGatherer;
use App\Services\Reports\Sections\ErrorLogGatherer;
use App\Services\Reports\Sections\ExecutiveSnapshotGatherer;
use App\Services\Reports\Sections\OverviewGatherer;
use App\Services\Reports\Sections\PerformanceGatherer;
use App\Services\Reports\Sections\PluginInventoryGatherer;
use App\Services\Reports\Sections\SearchConsoleGatherer;
use App\Services\Reports\Sections\SecurityChecksGatherer;
use App\Services\Reports\Sections\SecurityGatherer;
use App\Services\Reports\Sections\UpdatesGatherer;
use App\Services\Reports\Sections\UptimeGatherer;
use App\Services\Reports\Sections\WpUsersGatherer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class ReportGeneratorService
{
    protected array $data = [];

    protected ReportChartService $chartService;

    protected string $language = 'ro';

    public function __construct(
        protected Site $site,
        protected ReportTemplate $template,
        protected Carbon $periodStart,
        protected Carbon $periodEnd,
        protected array $excludedSections = [],
        ?ReportChartService $chartService = null,
    ) {
        $this->chartService = $chartService ?? new ReportChartService;
    }

    public function generate(): string
    {
        $this->language = $this->site->reportConfig?->language
            ?? $this->template->language
            ?? 'ro';

        $currentSnapshot = $this->getSnapshot($this->site, $this->periodStart);
        $previousSnapshot = $this->getPreviousSnapshot($this->site, $this->periodStart);

        // Gather all section data
        $gatherer = $this->makeDataGatherer($currentSnapshot, $previousSnapshot);
        $this->data = $gatherer->gather($this->excludedSections);

        // Build branding array
        $logoService = $this->makeLogoService();
        $branding = [
            'company_name' => $this->template->company_name ?? 'SimpleAd',
            'company_logo' => $logoService->resolveLogoPath($this->template->company_logo_path),
            'company_website' => $this->template->company_website,
            'primary_color' => $this->template->primary_color ?? '#7C3AED',
            'client_name' => $this->site->client?->name ?? $this->site->name,
            'client_logo' => $logoService->resolveClientLogo(),
        ];

        $sections = array_values(array_diff($this->template->sections ?? [], $this->excludedSections));

        // Pre-resolve section title/description overrides
        $sectionOverrides = [];
        foreach (['executive_snapshot', 'technical_stability', 'updates', 'backups', 'analytics', 'search_console', 'performance', 'infrastructure', 'recommendations', 'plugin_inventory', 'database_health', 'cloudflare', 'wp_users', 'security_checks'] as $key) {
            $sectionOverrides[$key] = [
                'title' => $this->template->getSectionTitle($key, $this->language),
                'description' => $this->template->getSectionDescription($key, $this->language),
            ];
        }

        $viewData = [
            'site' => $this->site,
            'data' => $this->data,
            'sections' => $sections,
            'excludedSections' => $this->excludedSections,
            'sectionOverrides' => $sectionOverrides,
            'sectionOptions' => $this->template->section_options ?? [],
            'branding' => $branding,
            'language' => $this->language,
            'periodStart' => $this->periodStart,
            'periodEnd' => $this->periodEnd,
            'introText' => $this->template->intro_text ?: __('report.default_intro', [], $this->language),
            'closingText' => $this->template->closing_text ?: __('report.default_closing', [], $this->language),
        ];

        // Logos as base64 for PDF embedding
        $viewData['logoBase64White'] = $logoService->getLogoAsBase64();
        $viewData['logoBase64Original'] = $logoService->getOriginalLogoAsBase64();
        $viewData['clientLogoBase64'] = $logoService->getClientLogoAsBase64();

        // Render cover HTML (standalone document, full-bleed, no header/footer)
        $coverHtml = View::make('reports.report-cover', $viewData)->render();

        // Render body HTML (in-flow header bars per section)
        $bodyHtml = View::make('reports.maintenance-report', $viewData)->render();

        // Render closing HTML (standalone document, full-bleed like cover)
        $closingHtml = View::make('reports.report-closing', $viewData)->render();

        // Footer HTML for body pages (Chrome native page numbering)
        $pc = e($branding['primary_color']);
        $reportTitle = mb_strtoupper(__('report.title', [], $this->language));
        $clientName = mb_strtoupper(e($branding['client_name']));
        $footerHtml = '<html><head><style>'
            .'#f { width: 100%; border-collapse: collapse; font-family: Inter, Arial, sans-serif; font-size: 7pt; color: #94a3b8; }'
            .'#f td { padding: 4px 12mm; vertical-align: middle; }'
            .'</style></head><body>'
            .'<table id="f"><tr>'
            .'<td style="text-align:left;">'.e($branding['company_website'] ?? 'simplead.ro').'</td>'
            .'<td style="text-align:center;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;">'.$reportTitle.' &middot; '.$clientName.'</td>'
            .'<td style="text-align:right;">'
            .'<span style="background:'.$pc.';color:#fff;padding:2px 8px;border-radius:3px;font-weight:600;font-size:7pt;">'
            .'<span class="pageNumber"></span> / <span class="totalPages"></span>'
            .'</span></td>'
            .'</tr></table>'
            .'</body></html>';

        // Generate PDF via Gotenberg
        $gotenberg = app(GotenbergService::class);
        $pdfBinary = $gotenberg->htmlToPdf($coverHtml, $bodyHtml, $closingHtml, $footerHtml, null);

        $clientName = $this->site->name;
        $safeName = Str::slug($clientName);
        $month = $this->periodEnd->format('m');
        $year = $this->periodEnd->format('Y');
        $date = $this->periodEnd->format('d.m.Y');

        $directory = 'reports/'.$safeName.'/'.$year;
        $fileName = $month.'. Maintenance Report - '.$clientName.' - '.$date.'.pdf';
        $filePath = $directory.'/'.$fileName;

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($filePath, $pdfBinary);

        return $filePath;
    }

    public function getData(): array
    {
        return $this->data;
    }

    protected function makeDataGatherer(
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
    ): ReportDataGatherer {
        return new ReportDataGatherer(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
            $currentSnapshot,
            $previousSnapshot,
            $this->chartService,
            $this->language,
            $this->buildGatherers(),
        );
    }

    protected function makeLogoService(): ReportLogoService
    {
        return new ReportLogoService($this->site, $this->template);
    }

    protected function buildGatherers(): array
    {
        return [
            new OverviewGatherer,
            new UpdatesGatherer,
            new UptimeGatherer,
            new BackupsGatherer,
            new AnalyticsGatherer,
            new SearchConsoleGatherer,
            new PerformanceGatherer,
            new DatabaseGatherer,
            new SecurityGatherer,
            new PluginInventoryGatherer,
            new DatabaseHealthGatherer,
            new CloudflareGatherer,
            new WpUsersGatherer,
            new SecurityChecksGatherer,
            new ExecutiveSnapshotGatherer,
            new DnsGatherer,
            new ErrorLogGatherer,
            new ContentFreshnessGatherer,
        ];
    }

    protected function getSnapshot(Site $site, Carbon $periodStart): ?SiteMonthlySnapshot
    {
        return SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', $periodStart->year)
            ->where('month', $periodStart->month)
            ->first();
    }

    protected function getPreviousSnapshot(Site $site, Carbon $periodStart): ?SiteMonthlySnapshot
    {
        $prev = $periodStart->copy()->subMonth();

        return SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', $prev->year)
            ->where('month', $prev->month)
            ->first();
    }
}
