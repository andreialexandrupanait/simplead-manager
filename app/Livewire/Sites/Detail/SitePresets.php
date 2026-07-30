<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SitePresetService;
use App\Services\SiteTweaksSettingsService;
use Livewire\Component;

/**
 * SPEC §13 — "Din cele 55 disponibile în conector. Restul rămân în cod, dispar
 * doar din interfață."
 *
 * The connector's full tweak catalogue was the only surface: four tabs of ~55
 * switches, of which the spec keeps ten. This screen IS those ten — the
 * "Standard SimpleAD" package, readable in one screen, with the one setting the
 * spec calls non-negotiable marked as such.
 *
 * Nothing is deleted: the full catalogue stays in the code and stays reachable
 * from here for the rare case that needs it (and so the §15 60-day usage
 * observation still has something to observe). What changes is which surface
 * comes first.
 */
class SitePresets extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    /** setting_key => bool, across every category in the package. */
    public array $toggles = [];

    /** setting_key => stored status string, for the per-setting badge. */
    public array $statuses = [];

    public bool $isDirty = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentState();
    }

    /**
     * The curated package, flattened for the view: one row per setting, in the
     * spec's own grouping (automatic everywhere / conditional on WooCommerce).
     *
     * @return array<string, list<array{key: string, category: string, label: string, description: string, essential: bool, enabled: bool, status: ?string}>>
     */
    public function getGroupsProperty(): array
    {
        $package = (array) config('site_presets.standard_simplead', []);
        $groups = [];

        foreach (['auto' => 'Aplicate pe toate site-urile', 'woo' => 'Doar unde există WooCommerce'] as $bucket => $title) {
            foreach ((array) ($package[$bucket] ?? []) as $category => $settings) {
                foreach ($settings as $key => $config) {
                    $groups[$title][] = [
                        'key' => $key,
                        'category' => $category,
                        'label' => $this->label($key),
                        'description' => $this->description($key),
                        'essential' => (bool) ($config['essential'] ?? false),
                        'enabled' => $this->toggles[$key] ?? false,
                        'status' => $this->statuses[$key] ?? null,
                    ];
                }
            }
        }

        return $groups;
    }

    public function toggleSetting(string $key): void
    {
        if (! array_key_exists($key, $this->toggles)) {
            return;
        }

        // SPEC §13: without "disable_all_updates" the site updates itself and
        // Manager loses control — the switch exists, but it does not turn off here.
        if ($this->isEssential($key) && $this->toggles[$key]) {
            $this->dispatch('notify', type: 'warning', message: __('This setting is essential — turning it off would let WordPress update itself behind Manager.'));

            return;
        }

        $this->toggles[$key] = ! $this->toggles[$key];
        $this->isDirty = true;
    }

    public function save(): void
    {
        $this->authorizeSiteModification($this->site);

        $service = app(SiteTweaksSettingsService::class);
        $byCategory = [];

        foreach ($this->packageSettings() as $key => $meta) {
            $enabled = $this->toggles[$key] ?? false;
            $byCategory[$meta['category']][$key] = ['enabled' => $enabled, 'value' => $enabled];
        }

        foreach ($byCategory as $category => $settings) {
            $service->applyMultiple($this->site, $category, $settings);
        }

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', __('Presets saved — they are pushed to WordPress right away.'));
    }

    /**
     * Re-apply the whole package as shipped, discarding local deviations. The
     * same path a site takes at first connection.
     */
    public function applyStandardPackage(): void
    {
        $this->authorizeSiteModification($this->site);

        $result = app(SitePresetService::class)->applyStandardPackage($this->site);

        $this->loadCurrentState();
        $this->isDirty = false;

        session()->flash('success', __(':n setting(s) applied from the Standard SimpleAD package.', ['n' => $result['applied']]));
    }

    protected function loadCurrentState(): void
    {
        $service = app(SiteTweaksSettingsService::class);
        $cache = [];

        foreach ($this->packageSettings() as $key => $meta) {
            $category = $meta['category'];
            $cache[$category] ??= $service->getSettingsForCategory($this->site, $category);

            $stored = $cache[$category]->get($key);
            $this->toggles[$key] = $stored !== null && (bool) $stored->is_enabled;
            $this->statuses[$key] = $stored?->status;
        }
    }

    /**
     * key => ['category' => …, 'essential' => bool] for every setting in the package.
     *
     * @return array<string, array{category: string, essential: bool}>
     */
    private function packageSettings(): array
    {
        $package = (array) config('site_presets.standard_simplead', []);
        $flat = [];

        foreach (['auto', 'woo'] as $bucket) {
            foreach ((array) ($package[$bucket] ?? []) as $category => $settings) {
                foreach ($settings as $key => $config) {
                    $flat[$key] = [
                        'category' => $category,
                        'essential' => (bool) ($config['essential'] ?? false),
                    ];
                }
            }
        }

        return $flat;
    }

    private function isEssential(string $key): bool
    {
        return $this->packageSettings()[$key]['essential'] ?? false;
    }

    private function label(string $key): string
    {
        return self::LABELS[$key]['label'] ?? str_replace('_', ' ', ucfirst($key));
    }

    private function description(string $key): string
    {
        return self::LABELS[$key]['description'] ?? '';
    }

    /**
     * Plain-language names for the ten, in the spec's own words — the point of
     * this screen is that it reads like a decision list, not a switch dump.
     */
    private const LABELS = [
        'disable_all_updates' => [
            'label' => 'Oprire update-uri automate',
            'description' => 'Fără ea, site-ul se actualizează singur și Manager pierde controlul.',
        ],
        'disable_emojis' => ['label' => 'Curățare head — emoji', 'description' => 'Scoate scriptul de emoji din front-end.'],
        'disable_rsd_link' => ['label' => 'Curățare head — RSD', 'description' => 'Link de descoperire XML-RPC, nefolosit.'],
        'disable_wlw_manifest' => ['label' => 'Curățare head — manifest WLW', 'description' => 'Windows Live Writer, dispărut de mult.'],
        'disable_shortlinks' => ['label' => 'Curățare head — shortlinks', 'description' => 'Linkul scurt generat automat.'],
        'disable_rest_api_links' => ['label' => 'Curățare head — linkuri REST', 'description' => 'Referințe REST în head.'],
        'disable_generator_tag' => ['label' => 'Curățare head — generator', 'description' => 'Ascunde versiunea WordPress.'],
        'disable_dns_prefetch' => ['label' => 'Curățare head — prefetch DNS', 'description' => 'Prefetch către s.w.org.'],
        'disable_self_pingbacks' => ['label' => 'Curățare head — pingback-uri proprii', 'description' => 'Site-ul nu se mai auto-notifică.'],
        'disable_dashicons' => ['label' => 'Curățare head — dashicons în frontend', 'description' => 'Iconițele de admin nu se mai încarcă pentru vizitatori.'],
        'heartbeat_control' => ['label' => 'Control Heartbeat', 'description' => 'Reglează frecvența în dashboard, editor și frontend — reduce încărcarea serverului.'],
        'revisions_control' => ['label' => 'Limitare revizii', 'description' => 'Previne umflarea bazei de date.'],
        'image_upload_control' => ['label' => 'Limite imagini la upload', 'description' => 'Lățime, înălțime și calitate JPEG maxime — doar la încărcări noi.'],
        'optimize_woocommerce' => [
            'label' => 'Optimizare WooCommerce',
            'description' => 'Fragmente coș oprite + scripturi Woo doar pe paginile de magazin. Coșul din header nu se mai împrospătează fără reîncărcare.',
        ],
    ];

    public function render()
    {
        return view('livewire.sites.detail.site-presets')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Presetări',
            ]);
    }
}
