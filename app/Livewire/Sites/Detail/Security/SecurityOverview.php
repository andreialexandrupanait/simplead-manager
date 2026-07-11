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

    /**
     * Per-category failed/pending counts for the Needs Attention card
     * (previously assembled in @php blocks inside the blade).
     *
     * @return Collection<int, array{key: string, label: string, failed: int, pending: int}>
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
                    $items[] = ['key' => $key, 'label' => (string) __($label), 'failed' => $failed, 'pending' => $pending];
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
