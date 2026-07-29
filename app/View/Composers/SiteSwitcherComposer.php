<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\SecurityIssue;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Feeds the site-context sidebar (SPEC §3.2 / §3.3):
 *
 *   - switcherSites          → the sites the current user may switch to (the
 *                              site-name-as-commutator dropdown in the header)
 *   - sidebarUpdatesCount     → pending plugin+theme updates for the *current*
 *                              site (badge on the "Pluginuri și teme" group)
 *   - sidebarSecurityCount    → active critical security issues for the current
 *                              site (badge on the "Securitate" group)
 *
 * Everything is scoped to what the user may see (visibleTo) so a client-scoped
 * user never sees other tenants' sites, and each signal is cached briefly so the
 * sidebar doesn't re-query on every request.
 */
class SiteSwitcherComposer
{
    /** How many sites to offer in the switcher dropdown before it gets unwieldy. */
    private const SWITCHER_LIMIT = 20;

    public function compose(View $view): void
    {
        $user = auth()->user();

        /** @var Site|null $current */
        $current = $view->getData()['site'] ?? null;

        if (! $user instanceof User) {
            $view->with([
                'switcherSites' => collect(),
                'sidebarUpdatesCount' => 0,
                'sidebarSecurityCount' => 0,
            ]);

            return;
        }

        $view->with([
            'switcherSites' => $this->switcherSites($user),
            'sidebarUpdatesCount' => $current instanceof Site ? $this->updatesCount($current) : 0,
            'sidebarSecurityCount' => $current instanceof Site ? $this->securityCount($current) : 0,
        ]);
    }

    /**
     * The user's visible sites for the header switcher, lightest possible payload
     * (only what the dropdown renders). Cached per user for 60s.
     *
     * @return \Illuminate\Support\Collection<int, Site>
     */
    private function switcherSites(User $user)
    {
        return Cache::remember("sidebar:switcher_sites:user:{$user->id}", 60, function () use ($user) {
            return Site::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->limit(self::SWITCHER_LIMIT)
                ->get(['id', 'name', 'url', 'favicon_path']);
        });
    }

    /** Pending plugin + theme updates for a single site (mirrors SidebarComposer). */
    private function updatesCount(Site $site): int
    {
        return Cache::remember("sidebar:site_updates_count:{$site->id}", 60, function () use ($site) {
            $plugins = SitePlugin::where('site_id', $site->id)->where('has_update', true)->count();
            $themes = SiteTheme::where('site_id', $site->id)->where('has_update', true)->count();

            return $plugins + $themes;
        });
    }

    /** Active critical security issues for a single site. */
    private function securityCount(Site $site): int
    {
        return Cache::remember("sidebar:site_security_count:{$site->id}", 60, function () use ($site) {
            return SecurityIssue::query()
                ->active()
                ->severity('critical')
                ->where('site_id', $site->id)
                ->count();
        });
    }
}
