<?php

namespace App\Livewire\Settings;

use App\Livewire\Forms\GeneralSettingsFormData;
use App\Livewire\Forms\SiteStatusFormData;
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

    private static ?bool $hasSiteStatusesTable = null;

    public GeneralSettingsFormData $form;
    public SiteStatusFormData $statusForm;

    // Branding paths (not part of the form -- display-only state)
    public ?string $faviconPath = null;
    public ?string $logoPath = null;

    // Site Status form editing ID
    public ?int $editingStatusId = null;

    public function mount(SettingsService $settings): void
    {
        $this->form->appName = $settings->get('app_name', 'SimpleAd Manager');
        $this->form->appUrl = $settings->get('app_url', config('app.url', ''));
        $this->form->defaultTimezone = $settings->get('default_timezone', 'UTC');
        $this->form->dateFormat = $settings->get('date_format', 'M d, Y');
        $this->form->defaultInterval = (int) $settings->get('default_interval', 300);
        $this->form->defaultTimeout = (int) $settings->get('default_timeout', 30);
        $this->form->alertAfterFailures = (int) $settings->get('alert_after_failures', 3);
        $this->faviconPath = $settings->get('branding.favicon');
        $this->logoPath = $settings->get('branding.logo');
    }

    #[Computed]
    public function siteStatuses()
    {
        if (!(static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            return collect();
        }

        return SiteStatus::withCount('sites')->orderBy('sort_order')->get();
    }

    public function save(SettingsService $settings): void
    {
        $this->form->validate();

        $settings->set('app_name', $this->form->appName, 'general', 'string');
        $settings->set('app_url', $this->form->appUrl, 'general', 'string');
        $settings->set('default_timezone', $this->form->defaultTimezone, 'general', 'string');
        $settings->set('date_format', $this->form->dateFormat, 'general', 'string');
        $settings->set('default_interval', $this->form->defaultInterval, 'monitoring', 'integer');
        $settings->set('default_timeout', $this->form->defaultTimeout, 'monitoring', 'integer');
        $settings->set('alert_after_failures', $this->form->alertAfterFailures, 'monitoring', 'integer');

        if ($this->form->favicon) {
            if ($this->faviconPath) {
                Storage::disk('public')->delete($this->faviconPath);
            }

            $path = $this->form->favicon->storeAs('branding', uniqid('favicon_') . '.' . $this->form->favicon->getClientOriginalExtension(), 'public');
            $settings->set('branding.favicon', $path, 'branding', 'string');
            $this->faviconPath = $path;
            $this->form->favicon = null;
        }

        if ($this->form->logo) {
            if ($this->logoPath) {
                Storage::disk('public')->delete($this->logoPath);
            }

            $path = $this->form->logo->storeAs('branding', uniqid('logo_') . '.' . $this->form->logo->getClientOriginalExtension(), 'public');
            $settings->set('branding.logo', $path, 'branding', 'string');
            $this->logoPath = $path;
            $this->form->logo = null;
        }

        $this->dispatch('notify', type: 'success', message: 'Settings saved successfully.');
    }

    public function removeFavicon(SettingsService $settings): void
    {
        if ($this->faviconPath) {
            Storage::disk('public')->delete($this->faviconPath);
            $settings->set('branding.favicon', null, 'branding', 'string');
            $this->faviconPath = null;
        }
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
        if (!(static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');
            return;
        }

        if ($id) {
            $status = SiteStatus::findOrFail($id);
            $this->editingStatusId = $status->id;
            $this->statusForm->statusName = $status->name;
            $this->statusForm->statusColor = $status->color;
            $this->statusForm->statusSortOrder = $status->sort_order;
        } else {
            $this->editingStatusId = null;
            $this->statusForm->statusName = '';
            $this->statusForm->statusColor = '#6b7280';
            $this->statusForm->statusSortOrder = 0;
        }

        $this->resetValidation();
        $this->dispatch('open-modal-status-form');
    }

    public function saveStatus(): void
    {
        if (!(static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');
            return;
        }

        $this->statusForm->validate();

        SiteStatus::updateOrCreate(
            ['id' => $this->editingStatusId],
            [
                'name' => $this->statusForm->statusName,
                'color' => $this->statusForm->statusColor,
                'sort_order' => $this->statusForm->statusSortOrder,
            ]
        );

        $this->dispatch('close-modal-status-form');
        unset($this->siteStatuses);
    }

    public function deleteStatus(int $id): void
    {
        if (!(static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
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
