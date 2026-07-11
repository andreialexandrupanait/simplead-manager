<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Security;

use App\Enums\SecuritySettingStatus;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\ModuleConfigService;
use App\Services\SecuritySettingsService;
use App\Services\SiteTweaksSettingsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityOverview extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function isModuleActive(): bool
    {
        return app(ModuleConfigService::class)->isModuleActive($this->site, 'security');
    }

    public function activateModule(): void
    {
        app(ModuleConfigService::class)->toggleModule($this->site, 'security', true);
        unset($this->isModuleActive);
    }

    #[Computed]
    public function securityScore(): ?int
    {
        return $this->site->security_hardening_score;
    }

    #[Computed]
    public function settingsByCategory(): Collection
    {
        return app(SecuritySettingsService::class)->getSettingsForSite($this->site);
    }

    /** Security categories (the score-bearing half of the hub). */
    private const CATEGORY_LABELS = [
        'hardening' => 'WordPress Hardening',
        'htaccess' => '.htaccess Rules',
        'login' => 'Login Protection',
        'captcha' => 'CAPTCHA',
        'ip_management' => 'IP Management',
        'activity_log' => 'Activity Log',
    ];

    /** WordPress Tweaks categories — same settings store, no score impact. */
    private const TWEAK_CATEGORY_LABELS = [
        'performance' => 'Performance',
        'site_control' => 'Site Control',
        'admin_ux' => 'Admin UX',
        'content_media' => 'Content & Media',
    ];

    /** Where each category is managed — used to link Needs Attention items. */
    private const CATEGORY_ROUTES = [
        'hardening' => 'sites.security.hardening',
        'htaccess' => 'sites.security.hardening',
        'login' => 'sites.security.login',
        'captcha' => 'sites.security.captcha',
        'ip_management' => 'sites.security.ip-management',
        'activity_log' => 'sites.security.activity',
        'performance' => 'sites.tweaks.performance',
        'site_control' => 'sites.tweaks.site-control',
        'admin_ux' => 'sites.tweaks.admin-ux',
        'content_media' => 'sites.tweaks.content-media',
    ];

    /**
     * Human label + managing page for every score-bearing setting key
     * (SecuritySettingsService::SCORE_WEIGHTS) — powers "Boost your score".
     */
    private const SCORE_ACTION_META = [
        'brute_force_protection' => ['label' => 'Brute force protection', 'route' => 'sites.security.login'],
        'disable_theme_editor' => ['label' => 'Disable theme editor', 'route' => 'sites.security.hardening'],
        'restrict_xmlrpc' => ['label' => 'Restrict XML-RPC', 'route' => 'sites.security.hardening'],
        'security_headers' => ['label' => 'Security headers', 'route' => 'sites.security.hardening'],
        'disable_user_enumeration' => ['label' => 'Disable user enumeration', 'route' => 'sites.security.hardening'],
        'block_default_files' => ['label' => 'Block default files', 'route' => 'sites.security.hardening'],
        'hide_wp_version' => ['label' => 'Hide WordPress version', 'route' => 'sites.security.hardening'],
        'block_readme_access' => ['label' => 'Block readme access', 'route' => 'sites.security.hardening'],
        'block_debug_log' => ['label' => 'Block debug.log access', 'route' => 'sites.security.hardening'],
        'disable_directory_listing' => ['label' => 'Disable directory listing', 'route' => 'sites.security.hardening'],
        'block_application_passwords' => ['label' => 'Block application passwords', 'route' => 'sites.security.hardening'],
        'restrict_rest_api' => ['label' => 'Restrict REST API', 'route' => 'sites.security.hardening'],
        'firewall_config' => ['label' => 'Firewall', 'route' => 'sites.security.ip-management'],
        'activity_log_config' => ['label' => 'Activity log', 'route' => 'sites.security.activity'],
    ];

    /**
     * Per-category failed/pending counts for the Needs Attention card
     * (previously assembled in @php blocks inside the blade).
     *
     * @return Collection<int, array{key: string, label: string, failed: int, pending: int, route: string}>
     */
    #[Computed]
    public function attentionItems(): Collection
    {
        $items = [];

        $categories = [
            [self::CATEGORY_LABELS, $this->settingsByCategory],
            [self::TWEAK_CATEGORY_LABELS, $this->tweakSettingsByCategory],
        ];

        foreach ($categories as [$labels, $grouped]) {
            foreach ($labels as $key => $label) {
                $settings = $grouped->get($key, collect());
                $failed = (int) $settings->where('status', SecuritySettingStatus::Failed)->count();
                $pending = (int) $settings->where('status', SecuritySettingStatus::Pending)->count();

                if ($failed > 0 || $pending > 0) {
                    $items[] = [
                        'key' => $key,
                        'label' => (string) __($label),
                        'failed' => $failed,
                        'pending' => $pending,
                        'route' => self::CATEGORY_ROUTES[$key],
                    ];
                }
            }
        }

        return collect($items);
    }

    #[Computed]
    public function tweakSettingsByCategory(): Collection
    {
        return app(SiteTweaksSettingsService::class)->getSettingsForSite($this->site);
    }

    /**
     * Top 3 unapplied score-bearing settings, heaviest first — makes the
     * score actionable instead of merely informative.
     *
     * @return array<int, array{key: string, label: string, weight: int, route: string}>
     */
    #[Computed]
    public function nextActions(): array
    {
        $applied = \App\Models\SecuritySetting::where('site_id', $this->site->id)
            ->where('is_enabled', true)
            ->whereNotNull('applied_at')
            ->whereNull('failed_at')
            ->pluck('setting_key')
            ->all();

        $actions = [];
        foreach (SecuritySettingsService::SCORE_WEIGHTS as $key => $weight) {
            if (! in_array($key, $applied, true)) {
                $actions[] = [
                    'key' => $key,
                    'label' => (string) __(self::SCORE_ACTION_META[$key]['label']),
                    'weight' => $weight,
                    'route' => self::SCORE_ACTION_META[$key]['route'],
                ];
            }
        }

        usort($actions, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return array_slice($actions, 0, 3);
    }

    /** Re-queue both push jobs — retries every failed/pending setting. */
    public function repushSettings(): void
    {
        $this->authorizeSiteModification($this->site);

        app(SecuritySettingsService::class)->pushToPlugin($this->site);
        app(SiteTweaksSettingsService::class)->pushToPlugin($this->site);

        unset($this->settingsByCategory, $this->tweakSettingsByCategory, $this->attentionItems);
        $this->dispatch('notify', type: 'success', message: __('Re-push queued — settings will be re-applied within a minute.'));
    }

    /** @return array{lastScanAt: string|null, openCriticalHigh: int} */
    #[Computed]
    public function scanSummary(): array
    {
        return [
            'lastScanAt' => $this->site->securityScans()->latest('scanned_at')->value('scanned_at'),
            'openCriticalHigh' => $this->site->securityIssues()
                ->whereIn('severity', ['critical', 'high'])
                ->where('is_fixed', false)
                ->where('is_ignored', false)
                ->count(),
        ];
    }

    /** @return array{total: int, admins: int} */
    #[Computed]
    public function usersSummary(): array
    {
        $byRole = $this->site->siteUsers()
            ->selectRaw("count(*) as total, count(*) filter (where role = 'administrator') as admins")
            ->first();

        return [
            'total' => (int) ($byRole->total ?? 0),
            'admins' => (int) ($byRole->admins ?? 0),
        ];
    }

    #[Computed]
    public function lastSyncAt(): ?string
    {
        return $this->site->securitySettings()->max('applied_at');
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Security & Tweaks',
            ]);
    }
}
