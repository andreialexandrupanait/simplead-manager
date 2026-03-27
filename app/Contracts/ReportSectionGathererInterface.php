<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use Carbon\Carbon;

interface ReportSectionGathererInterface
{
    public function supports(string $sectionKey): bool;

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array;
}
