<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckBrokenLinks;
use App\Jobs\CheckWooHealth;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\FormCheck;
use App\Models\LinkCheckResult;
use App\Models\LinkCheckRun;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\WooCheck;
use App\Services\ContactFormChecker;
use App\Support\HumanError;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * SPEC §3.2 "Verificări" — Formulare · WooCommerce · Linkuri rupte · Erori PHP.
 *
 * Every one of these checks already existed and ran (or, for the form test, could
 * not run at all): results were written to form_checks, woo_checks, link_check_runs
 * and php_error_logs and read by nobody. There was no screen, no report section,
 * and in the form test's case no caller anywhere in the codebase.
 *
 * Four routes onto one component, following the plugins/themes/core/licenses
 * pattern — the sidebar can link straight to a tab.
 *
 * Cron and Bază de date are the other two entries in the spec's group; they have
 * their own long-standing screens and are left where they are.
 */
class SiteChecks extends Component
{
    use WithSiteAuthorization;

    public const TABS = ['forms', 'woo', 'links', 'errors'];

    public Site $site;

    public string $tab = 'forms';

    /** Result of the last on-demand form action, shown until the next one. */
    public ?array $formResult = null;

    public ?string $formError = null;

    /**
     * Route name => the tab it lands on.
     *
     * Derived from the route NAME rather than a route default or a mount
     * parameter, for the reason already learned in SitePlugins: `tab` is a public
     * property, so Livewire would assign a same-named parameter straight onto it
     * and skip validation, and a route default confuses mount-argument resolution.
     */
    private const TAB_ROUTES = [
        'sites.checks.forms' => 'forms',
        'sites.checks.woo' => 'woo',
        'sites.checks.links' => 'links',
        'sites.checks.errors' => 'errors',
    ];

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);

        $this->site = $site;

        $routeName = request()->route()?->getName();
        if ($routeName !== null && isset(self::TAB_ROUTES[$routeName])) {
            $this->tab = self::TAB_ROUTES[$routeName];
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    // ---------------------------------------------------------------- Formulare

    #[Computed]
    public function formCheck(): ?FormCheck
    {
        return FormCheck::where('site_id', $this->site->id)->first();
    }

    /**
     * Ask which form plugin is present and whether it is in the suppression
     * allowlist. Safe by construction: capability() never submits anything.
     */
    public function checkFormCapability(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->formError = null;
        $this->formResult = app(ContactFormChecker::class)->capability($this->site);

        unset($this->formCheck);
    }

    /**
     * Where this site's test notification gets redirected, so the screen can say
     * plainly whether delivery is verifiable at all here.
     */
    #[Computed]
    public function formDeliveryAddress(): ?string
    {
        return app(ContactFormChecker::class)->deliveryAddress($this->site);
    }

    /**
     * Run the real, gated submission. The service refuses outright for any plugin
     * without a proven suppression path, so an unsupported site is never touched.
     */
    public function runFormTest(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->formError = null;

        try {
            $this->formResult = app(ContactFormChecker::class)->runGatedTest($this->site);
        } catch (\Throwable $e) {
            $this->formResult = null;
            $this->formError = HumanError::from($e);
        }

        unset($this->formCheck);
    }

    // -------------------------------------------------------------- WooCommerce

    #[Computed]
    public function wooCheck(): ?WooCheck
    {
        return WooCheck::where('site_id', $this->site->id)->first();
    }

    public function runWooCheck(): void
    {
        $this->authorizeSiteModification($this->site);

        CheckWooHealth::dispatch($this->site->id);

        $this->dispatch('notify', type: 'info', message: __('WooCommerce check queued.'));
    }

    // ------------------------------------------------------------ Linkuri rupte

    #[Computed]
    public function linkRun(): ?LinkCheckRun
    {
        return LinkCheckRun::where('site_id', $this->site->id)
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * The broken links of the newest run. SPEC §5.7 reports on the DIFFERENCE
     * month to month — that headline lives in the view, built from the run's
     * new_broken_count/resolved_count — but the list itself is still needed to
     * actually go and fix them.
     *
     * @return \Illuminate\Support\Collection<int, LinkCheckResult>
     */
    #[Computed]
    public function brokenLinks()
    {
        $run = $this->linkRun;

        if (! $run) {
            return collect();
        }

        return LinkCheckResult::where('run_id', $run->id)
            ->where('is_broken', true)
            ->orderBy('url')
            ->limit(200)
            ->get();
    }

    /**
     * Internal redirect chains (A → B → C) found by the last scan.
     *
     * Shown, not acted on: SiteRedirect rows are rules the Manager pushes to the
     * site, so turning an observation into one would create a live redirect as a
     * side effect of a scan. Deciding that is a person's job.
     *
     * @return \Illuminate\Support\Collection<int, LinkCheckResult>
     */
    #[Computed]
    public function redirectChains()
    {
        $run = $this->linkRun;

        if (! $run) {
            return collect();
        }

        return LinkCheckResult::where('run_id', $run->id)
            ->whereNotNull('redirect_chain')
            ->orderBy('url')
            ->limit(50)
            ->get();
    }

    public function runLinkCheck(): void
    {
        $this->authorizeSiteModification($this->site);

        CheckBrokenLinks::dispatch($this->site->id);

        $this->dispatch('notify', type: 'info', message: __('Broken-link scan queued.'));
    }

    // --------------------------------------------------------------- Erori PHP

    /**
     * @return \Illuminate\Support\Collection<int, PhpErrorLog>
     */
    #[Computed]
    public function phpErrors()
    {
        return PhpErrorLog::where('site_id', $this->site->id)
            ->where('is_resolved', false)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();
    }

    /**
     * Which plugin or theme is responsible for the most errors — the attribution
     * SPEC §5.6 asks the connector to make possible, and the raw material for
     * §12.4's sentence in the report.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PhpErrorLog>
     */
    #[Computed]
    public function errorsBySource()
    {
        return PhpErrorLog::where('site_id', $this->site->id)
            ->where('is_resolved', false)
            ->selectRaw("COALESCE(source_type, 'unknown') as source_type, COALESCE(source_slug, '') as source_slug, COUNT(*) as signatures, SUM(COALESCE(count, 1)) as occurrences")
            ->groupBy('source_type', 'source_slug')
            ->orderByDesc('occurrences')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.sites.detail.site-checks')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — '.__('Checks'),
            ]);
    }
}
