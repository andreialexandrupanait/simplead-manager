<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Livewire\Component;

class TweaksContentMedia extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public array $toggles = [];

    public array $settingStatuses = [];

    public bool $isDirty = false;

    // Content duplication config
    public array $duplicationPostTypes = ['post', 'page'];

    public bool $duplicationCopyTaxonomies = true;

    public bool $duplicationCopyMeta = true;

    public bool $duplicationCopyFeaturedImage = true;

    public string $duplicationStatus = 'draft';

    public string $duplicationTitlePrefix = '';

    public string $duplicationTitleSuffix = ' (Copy)';

    public string $duplicationRedirectAfter = 'edit';

    // Content order config
    public array $contentOrderPostTypes = ['page'];

    protected array $simpleToggleKeys = [
        'media_replacement',
        'svg_upload',
        'avif_upload',
        'external_permalinks',
        'open_external_links_new_tab',
        'auto_publish_missed_schedule',
        'media_visibility_control',
    ];

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentState();
    }

    protected function loadCurrentState(): void
    {
        $settings = app(SiteTweaksSettingsService::class)
            ->getSettingsForCategory($this->site, 'content_media');

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $this->toggles[$key] = $settings->get($key)?->is_enabled ?? false;
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }

        // Content duplication
        $dup = $settings->get('content_duplication');
        if ($dup && $dup->is_enabled) {
            $this->toggles['content_duplication'] = true;
            $config = $dup->setting_value ?? [];
            $this->duplicationPostTypes = $config['post_types'] ?? ['post', 'page'];
            $this->duplicationCopyTaxonomies = $config['copy_taxonomies'] ?? true;
            $this->duplicationCopyMeta = $config['copy_meta'] ?? true;
            $this->duplicationCopyFeaturedImage = $config['copy_featured_image'] ?? true;
            $this->duplicationStatus = $config['duplicate_status'] ?? 'draft';
            $this->duplicationTitlePrefix = $config['title_prefix'] ?? '';
            $this->duplicationTitleSuffix = $config['title_suffix'] ?? ' (Copy)';
            $this->duplicationRedirectAfter = $config['redirect_after'] ?? 'edit';
        } else {
            $this->toggles['content_duplication'] = false;
        }
        $this->settingStatuses['content_duplication'] = $dup?->status;

        // Content order
        $order = $settings->get('content_order');
        if ($order && $order->is_enabled) {
            $this->toggles['content_order'] = true;
            $config = $order->setting_value ?? [];
            $this->contentOrderPostTypes = $config['post_types'] ?? ['page'];
        } else {
            $this->toggles['content_order'] = false;
        }
        $this->settingStatuses['content_order'] = $order?->status;
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = ! $this->toggles[$key];
            $this->isDirty = true;
        }
    }

    public function toggleDuplicationPostType(string $type): void
    {
        if (in_array($type, $this->duplicationPostTypes, true)) {
            $this->duplicationPostTypes = array_values(array_diff($this->duplicationPostTypes, [$type]));
        } else {
            $this->duplicationPostTypes[] = $type;
        }
        $this->isDirty = true;
    }

    public function toggleContentOrderPostType(string $type): void
    {
        if (in_array($type, $this->contentOrderPostTypes, true)) {
            $this->contentOrderPostTypes = array_values(array_diff($this->contentOrderPostTypes, [$type]));
        } else {
            $this->contentOrderPostTypes[] = $type;
        }
        $this->isDirty = true;
    }

    public function updated($property): void
    {
        $this->isDirty = true;
    }

    public function save(): void
    {
        $this->authorizeSiteModification($this->site);
        $service = app(SiteTweaksSettingsService::class);
        $settings = [];

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $enabled = $this->toggles[$key] ?? false;
            $settings[$key] = ['enabled' => $enabled, 'value' => $enabled];
        }

        // Content duplication
        $settings['content_duplication'] = [
            'enabled' => $this->toggles['content_duplication'] ?? false,
            'value' => [
                'post_types' => $this->duplicationPostTypes,
                'copy_taxonomies' => $this->duplicationCopyTaxonomies,
                'copy_meta' => $this->duplicationCopyMeta,
                'copy_featured_image' => $this->duplicationCopyFeaturedImage,
                'duplicate_status' => $this->duplicationStatus,
                'title_prefix' => $this->duplicationTitlePrefix,
                'title_suffix' => $this->duplicationTitleSuffix,
                'redirect_after' => $this->duplicationRedirectAfter,
            ],
        ];

        // Content order
        $settings['content_order'] = [
            'enabled' => $this->toggles['content_order'] ?? false,
            'value' => [
                'post_types' => $this->contentOrderPostTypes,
            ],
        ];

        $service->applyMultiple($this->site, 'content_media', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', __('Content & Media settings saved. Changes will be applied shortly.'));
        $this->redirect(route('sites.tweaks.content-media', $this->site), navigate: false);
    }

    public function verifySettings(): void
    {
        $this->authorizeSiteModification($this->site);
        try {
            $api = app(\App\Services\WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('GET', '/site-tweaks-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.tweaks.content-media', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $cmVerified = $data['content_media']['verified'] ?? [];
            $cmSettings = $data['content_media']['settings'] ?? [];

            $settings = $this->site->securitySettings()
                ->where('category', 'content_media')
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                $key = $setting->setting_key;
                $active = ! empty($cmVerified[$key]['active'])
                    || (empty($cmVerified) && ! empty($cmSettings[$key]));

                if ($active) {
                    $setting->update(['applied_at' => $now, 'failed_at' => null, 'failure_reason' => null]);
                    $verified++;
                } else {
                    $setting->update(['failed_at' => $now, 'failure_reason' => 'Not active on WordPress']);
                    $mismatches++;
                }
            }

            if ($mismatches > 0) {
                app(SiteTweaksSettingsService::class)->pushToPlugin($this->site);
                session()->flash('success', "{$verified} verified, {$mismatches} mismatches — re-push triggered.");
            } else {
                session()->flash('success', "All {$verified} settings verified on WordPress.");
            }
        } catch (\Exception $e) {
            \Log::error('Verify content media settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.tweaks.content-media', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-content-media')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Content & Media',
            ]);
    }
}
