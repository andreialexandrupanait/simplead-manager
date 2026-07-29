<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\PhpErrorLog;
use App\Models\SecurityIssue;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Feeds the fleet sidebar (SPEC §3.1) its severity counters:
 *   - updatesCount   → total plugin+theme updates available on connected sites
 *   - securityCount  → number of sites with active critical security issues
 *   - errorLogsCount → number of sites with unresolved PHP errors
 *
 * Every signal is scoped to the sites the current user may see (visibleTo),
 * so a client-scoped user never sees fleet-wide totals. Each is cached for
 * 60s under a per-user key so the counters don't re-query on every request
 * while staying fresh enough to reflect recent syncs/scans.
 */
class SidebarComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();

        // Out-of-request / unauthenticated render: bind zeros, don't leak totals.
        if (! $user instanceof User) {
            $view->with([
                'updatesCount' => 0,
                'securityCount' => 0,
                'errorLogsCount' => 0,
            ]);

            return;
        }

        $view->with([
            'updatesCount' => $this->updatesCount($user),
            'securityCount' => $this->securityCount($user),
            'errorLogsCount' => $this->errorLogsCount($user),
        ]);
    }

    /**
     * Total pending plugin + theme updates across the user's connected sites.
     * Mirrors UpdatesOverview::stats() 'total' (plugin updates + theme updates)
     * so the sidebar badge and the Updates screen agree.
     */
    private function updatesCount(User $user): int
    {
        return (int) Cache::remember("sidebar:updates_count:user:{$user->id}", 60, function () use ($user) {
            $connectedVisibleSite = fn ($q) => $q
                ->where('is_connected', true)
                ->visibleTo($user);

            $plugins = SitePlugin::where('has_update', true)
                ->whereHas('site', $connectedVisibleSite)
                ->count();

            $themes = SiteTheme::where('has_update', true)
                ->whereHas('site', $connectedVisibleSite)
                ->count();

            return $plugins + $themes;
        });
    }

    /**
     * Number of sites that have at least one active (not fixed, not ignored)
     * critical security issue from the existing security scans (SecurityIssue).
     */
    private function securityCount(User $user): int
    {
        return (int) Cache::remember("sidebar:security_count:user:{$user->id}", 60, function () use ($user) {
            return SecurityIssue::query()
                ->active()
                ->severity('critical')
                ->whereHas('site', fn ($q) => $q->visibleTo($user))
                ->distinct('site_id')
                ->count('site_id');
        });
    }

    /**
     * Number of sites with unresolved PHP errors (existing error-logs module).
     * Counts distinct sites, matching ErrorLogsOverview::stats() 'sites'.
     */
    private function errorLogsCount(User $user): int
    {
        return (int) Cache::remember("sidebar:error_logs_count:user:{$user->id}", 60, function () use ($user) {
            return PhpErrorLog::query()
                ->unresolved()
                ->whereHas('site', fn ($q) => $q->visibleTo($user))
                ->distinct('site_id')
                ->count('site_id');
        });
    }
}
