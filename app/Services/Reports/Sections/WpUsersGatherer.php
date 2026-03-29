<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\SiteUser;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class WpUsersGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'wp_users';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $users = SiteUser::where('site_id', $site->id)->get();

        if ($users->isEmpty()) {
            return [];
        }

        $byRole = $users->groupBy('role')->map->count()->sortDesc()->toArray();

        $admins = $users->where('role', 'administrator')->values();
        $recentLogins = $users->filter(fn ($u) => $u->last_login_at && $u->last_login_at->greaterThan(now()->subDays(30)))->count();
        $neverLoggedIn = $users->filter(fn ($u) => $u->last_login_at === null)->count();

        $roleColors = ['#2563eb', '#0d9488', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
        $roleBarData = [];
        $idx = 0;
        foreach ($byRole as $role => $count) {
            $roleBarData[] = [
                'value' => $count,
                'label' => ucfirst($role),
                'color' => $roleColors[$idx % count($roleColors)],
            ];
            $idx++;
        }
        $roleBarChart = $chartService->generateHorizontalBarData($roleBarData);

        return [
            'total_users' => $users->count(),
            'administrators' => $admins->count(),
            'recent_logins' => $recentLogins,
            'never_logged_in' => $neverLoggedIn,
            'by_role' => $byRole,
            'user_list' => $users->map(fn ($u) => [
                'username' => $u->username ?: ($u->display_name ?: ($u->email ? explode('@', (string) $u->email)[0] : 'N/A')),
                'email' => $u->email,
                'role' => $u->role ? ucfirst($u->role) : 'N/A',
                'last_login_at' => $u->last_login_at?->format('d/m/Y'),
            ])->toArray(),
            'role_bar_chart' => $roleBarChart,
        ];
    }
}
