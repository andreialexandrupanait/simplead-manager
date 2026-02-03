<?php

namespace App\Livewire\Settings;

use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\SettingsService;
use Livewire\Component;

class GeneralSettings extends Component
{
    // Application
    public string $appName = 'SimpleAd Manager';
    public string $appUrl = '';
    public string $defaultTimezone = 'UTC';
    public string $dateFormat = 'M d, Y';

    // Monitoring defaults
    public int $defaultInterval = 300;
    public int $defaultTimeout = 30;
    public int $alertAfterFailures = 3;
    public int $dataRetentionDays = 90;

    public function mount(SettingsService $settings): void
    {
        $this->appName = $settings->get('app_name', 'SimpleAd Manager');
        $this->appUrl = $settings->get('app_url', config('app.url', ''));
        $this->defaultTimezone = $settings->get('default_timezone', 'UTC');
        $this->dateFormat = $settings->get('date_format', 'M d, Y');
        $this->defaultInterval = (int) $settings->get('default_interval', 300);
        $this->defaultTimeout = (int) $settings->get('default_timeout', 30);
        $this->alertAfterFailures = (int) $settings->get('alert_after_failures', 3);
        $this->dataRetentionDays = (int) $settings->get('data_retention_days', 90);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'appName' => 'required|string|max:255',
            'appUrl' => 'nullable|url|max:255',
            'defaultTimezone' => 'required|timezone',
            'dateFormat' => 'required|string|max:50',
            'defaultInterval' => 'required|integer|min:60|max:3600',
            'defaultTimeout' => 'required|integer|min:5|max:120',
            'alertAfterFailures' => 'required|integer|min:1|max:10',
            'dataRetentionDays' => 'required|integer|min:7|max:365',
        ]);

        $settings->set('app_name', $this->appName, 'general', 'string');
        $settings->set('app_url', $this->appUrl, 'general', 'string');
        $settings->set('default_timezone', $this->defaultTimezone, 'general', 'string');
        $settings->set('date_format', $this->dateFormat, 'general', 'string');
        $settings->set('default_interval', $this->defaultInterval, 'monitoring', 'integer');
        $settings->set('default_timeout', $this->defaultTimeout, 'monitoring', 'integer');
        $settings->set('alert_after_failures', $this->alertAfterFailures, 'monitoring', 'integer');
        $settings->set('data_retention_days', $this->dataRetentionDays, 'monitoring', 'integer');

        session()->flash('settings-saved', true);
    }

    public function purgeMonitoringData(): void
    {
        UptimeCheck::query()->delete();
        UptimeIncident::query()->delete();

        session()->flash('data-purged', true);
    }

    public function render()
    {
        return view('livewire.settings.general-settings')
            ->layout('components.layouts.app', ['title' => 'General Settings']);
    }
}
