<?php

namespace App\Livewire\Settings;

use App\Models\SiteStatus;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class GeneralSettings extends Component
{
    use WithFileUploads;

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

    // Logo
    public $logo;
    public ?string $logoPath = null;

    // Site Status form
    public ?int $editingStatusId = null;
    public string $statusName = '';
    public string $statusColor = '#6b7280';
    public int $statusSortOrder = 0;

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
        $this->logoPath = $settings->get('branding.logo');
    }

    #[Computed]
    public function siteStatuses()
    {
        if (!Schema::hasTable('site_statuses')) {
            return collect();
        }

        return SiteStatus::withCount('sites')->orderBy('sort_order')->get();
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
            'logo' => 'nullable|image|max:2048',
        ]);

        $settings->set('app_name', $this->appName, 'general', 'string');
        $settings->set('app_url', $this->appUrl, 'general', 'string');
        $settings->set('default_timezone', $this->defaultTimezone, 'general', 'string');
        $settings->set('date_format', $this->dateFormat, 'general', 'string');
        $settings->set('default_interval', $this->defaultInterval, 'monitoring', 'integer');
        $settings->set('default_timeout', $this->defaultTimeout, 'monitoring', 'integer');
        $settings->set('alert_after_failures', $this->alertAfterFailures, 'monitoring', 'integer');
        $settings->set('data_retention_days', $this->dataRetentionDays, 'monitoring', 'integer');

        if ($this->logo) {
            // Delete old logo if exists
            if ($this->logoPath) {
                Storage::disk('public')->delete($this->logoPath);
            }

            $path = $this->logo->store('branding', 'public');
            $settings->set('branding.logo', $path, 'branding', 'string');
            $this->logoPath = $path;
            $this->logo = null;
        }

        $this->dispatch('notify', type: 'success', message: 'Settings saved successfully.');
    }

    public function removeLogo(SettingsService $settings): void
    {
        if ($this->logoPath) {
            Storage::disk('public')->delete($this->logoPath);
            $settings->set('branding.logo', null, 'branding', 'string');
            $this->logoPath = null;
        }
    }

    public function openStatusForm(?int $id = null): void
    {
        if (!Schema::hasTable('site_statuses')) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');
            return;
        }

        if ($id) {
            $status = SiteStatus::findOrFail($id);
            $this->editingStatusId = $status->id;
            $this->statusName = $status->name;
            $this->statusColor = $status->color;
            $this->statusSortOrder = $status->sort_order;
        } else {
            $this->editingStatusId = null;
            $this->statusName = '';
            $this->statusColor = '#6b7280';
            $this->statusSortOrder = 0;
        }

        $this->resetValidation();
        $this->dispatch('open-modal-status-form');
    }

    public function saveStatus(): void
    {
        if (!Schema::hasTable('site_statuses')) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');
            return;
        }

        $this->validate([
            'statusName' => 'required|string|max:255',
            'statusColor' => 'required|string|max:7',
            'statusSortOrder' => 'required|integer|min:0',
        ]);

        SiteStatus::updateOrCreate(
            ['id' => $this->editingStatusId],
            [
                'name' => $this->statusName,
                'color' => $this->statusColor,
                'sort_order' => $this->statusSortOrder,
            ]
        );

        $this->dispatch('close-modal-status-form');
        unset($this->siteStatuses);
    }

    public function deleteStatus(int $id): void
    {
        if (!Schema::hasTable('site_statuses')) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');
            return;
        }

        $status = SiteStatus::withCount('sites')->findOrFail($id);

        if ($status->sites_count > 0) {
            $this->dispatch('notify', type: 'error', message: "Cannot delete \"{$status->name}\" — {$status->sites_count} site(s) are assigned to it.");
            return;
        }

        $status->delete();
        unset($this->siteStatuses);
    }

    public function purgeMonitoringData(): void
    {
        UptimeCheck::query()->delete();
        UptimeIncident::query()->delete();

        $this->dispatch('notify', type: 'warning', message: 'Monitoring data has been purged.');
    }

    public function render()
    {
        return view('livewire.settings.general-settings')
            ->layout('components.layouts.app', ['title' => 'General Settings']);
    }
}
