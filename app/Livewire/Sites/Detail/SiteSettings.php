<?php

namespace App\Livewire\Sites\Detail;

use App\Models\Site;
use App\Models\SitePreset;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSettings extends Component
{
    public Site $site;

    public ?int $selectedPresetId = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->selectedPresetId = $site->applied_preset_id;
    }

    #[Computed]
    public function presets()
    {
        return SitePreset::with('presetModules')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function moduleConfig(): array
    {
        return app(ModuleConfigService::class)->getConfig($this->site);
    }

    #[Computed]
    public function moduleLabels(): array
    {
        return [
            'uptime' => 'Uptime Monitoring',
            'backup' => 'Backups',
            'ssl' => 'SSL Monitoring',
            'performance' => 'Performance Tests',
            'security' => 'Security Scans',
            'analytics' => 'Google Analytics',
            'search_console' => 'Search Console',
            'cloudflare' => 'Cloudflare',
            'database_cleanup' => 'Database Cleanup',
        ];
    }

    #[Computed]
    public function moduleIcons(): array
    {
        return [
            'uptime' => 'activity',
            'backup' => 'hard-drive',
            'ssl' => 'lock',
            'performance' => 'zap',
            'security' => 'shield',
            'analytics' => 'bar-chart-2',
            'search_console' => 'search',
            'cloudflare' => 'cloud',
            'database_cleanup' => 'database',
        ];
    }

    public function applyPreset(): void
    {
        if (!$this->selectedPresetId) {
            $this->dispatch('notify', type: 'error', message: 'Please select a preset.');
            return;
        }

        $preset = SitePreset::findOrFail($this->selectedPresetId);
        app(ModuleConfigService::class)->applyPreset($this->site, $preset);

        $this->site->refresh();
        unset($this->moduleConfig);

        $this->dispatch('notify', type: 'success', message: "Preset \"{$preset->name}\" applied.");
    }

    public function toggleModule(string $module): void
    {
        $service = app(ModuleConfigService::class);
        $currentlyActive = $service->isModuleActive($this->site, $module);
        $service->toggleModule($this->site, $module, !$currentlyActive);

        $this->site->refresh();
        unset($this->moduleConfig);
    }

    public function updateInterval(string $module, int $minutes): void
    {
        app(ModuleConfigService::class)->updateInterval($this->site, $module, $minutes);

        $this->site->refresh();
        unset($this->moduleConfig);

        $this->dispatch('notify', type: 'success', message: 'Interval updated.');
    }

    public function getIntervalOptions(string $module): array
    {
        return match ($module) {
            'uptime' => [
                3 => '3 min', 5 => '5 min', 10 => '10 min', 15 => '15 min', 30 => '30 min',
            ],
            'security' => [
                360 => '6 hours', 720 => '12 hours', 1440 => 'Daily', 10080 => 'Weekly',
            ],
            default => [
                360 => '6 hours', 720 => '12 hours', 1440 => 'Daily', 10080 => 'Weekly',
            ],
        };
    }

    public function render()
    {
        return view('livewire.sites.detail.site-settings')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Settings',
            ]);
    }
}
