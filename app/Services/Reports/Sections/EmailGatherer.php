<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class EmailGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'email';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $email = $site->latestEmailHealthCheck;
        if (! $email) {
            return [];
        }

        return [
            'score' => $email->score,
            'status' => $email->status,
            'spf_exists' => $email->spf_exists,
            'spf_status' => $email->spf_status,
            'dkim_exists' => $email->dkim_exists,
            'dkim_status' => $email->dkim_status,
            'dmarc_exists' => $email->dmarc_exists,
            'dmarc_policy' => $email->dmarc_policy,
            'checked_at' => $email->checked_at?->format('d/m/Y'),
        ];
    }
}
