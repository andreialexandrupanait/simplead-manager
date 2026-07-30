<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Forms\GeneralSettingsFormData;
use App\Livewire\Forms\SiteStatusFormData;
use App\Models\SiteStatus;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class GeneralSettings extends Component
{
    use WithFileUploads;

    public GeneralSettingsFormData $form;

    public SiteStatusFormData $statusForm;

    // Branding paths (not part of the form -- display-only state)
    public ?string $faviconPath = null;

    public ?string $logoPath = null;

    // Site Status form editing ID
    public ?int $editingStatusId = null;

    public function mount(SettingsService $settings): void
    {
        // Coalesce AFTER the get(), not through its $default argument.
        // SettingsService::get() only falls back to the default when the row is
        // missing entirely; a row that exists holding NULL — exactly what
        // removeFavicon()/removeLogo() write — comes back as null. Assigning
        // that to the non-nullable string/int properties below is a TypeError
        // under strict_types, i.e. a 500 on page load.
        $this->form->appName = (string) ($settings->get('app_name') ?? 'SimpleAd Manager');
        $this->form->appUrl = (string) ($settings->get('app_url') ?? config('app.url', ''));
        $this->form->defaultTimezone = (string) ($settings->get('default_timezone') ?? 'UTC');
        $this->form->dateFormat = (string) ($settings->get('date_format') ?? 'M d, Y');
        $this->form->defaultInterval = (int) ($settings->get('default_interval') ?? 300);
        $this->form->defaultTimeout = (int) ($settings->get('default_timeout') ?? 30);
        $this->form->alertAfterFailures = (int) ($settings->get('alert_after_failures') ?? 3);
        $this->form->dashboardPerPage = (int) ($settings->get('dashboard_per_page') ?? 30);
        $this->form->sitesPerPage = (int) ($settings->get('sites_per_page') ?? 50);

        $accentColor = $settings->get('branding.accent_color');
        $this->form->accentColor = $accentColor === null ? null : (string) $accentColor;

        $faviconPath = $settings->get('branding.favicon');
        $this->faviconPath = $faviconPath === null ? null : (string) $faviconPath;

        $logoPath = $settings->get('branding.logo');
        $this->logoPath = $logoPath === null ? null : (string) $logoPath;
    }

    #[Computed]
    public function siteStatuses()
    {
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
        $settings->set('dashboard_per_page', $this->form->dashboardPerPage, 'general', 'integer');
        $settings->set('sites_per_page', $this->form->sitesPerPage, 'general', 'integer');
        $settings->set('branding.accent_color', $this->form->accentColor, 'branding', 'string');

        if ($this->form->favicon) {
            if ($this->faviconPath) {
                Storage::disk('public')->delete($this->faviconPath);
            }

            $path = $this->form->favicon->storeAs('branding', uniqid('favicon_').'.'.$this->form->favicon->getClientOriginalExtension(), 'public');
            $settings->set('branding.favicon', $path, 'branding', 'string');
            $this->faviconPath = $path;
            $this->form->favicon = null;
        }

        if ($this->form->logo) {
            if ($this->logoPath) {
                Storage::disk('public')->delete($this->logoPath);
            }

            $path = $this->form->logo->storeAs('branding', uniqid('logo_').'.'.$this->form->logo->getClientOriginalExtension(), 'public');
            $settings->set('branding.logo', $path, 'branding', 'string');
            $this->logoPath = $path;
            $this->form->logo = null;
        }

        $this->dispatch('notify', type: 'success', message: __('Settings saved successfully.'));
    }

    public function removeFavicon(SettingsService $settings): void
    {
        if ($this->faviconPath) {
            Storage::disk('public')->delete($this->faviconPath);
            $settings->set('branding.favicon', null, 'branding', 'string');
            $this->faviconPath = null;
        }

        $this->dispatch('notify', type: 'success', message: __('Favicon removed.'));
    }

    public function removeLogo(SettingsService $settings): void
    {
        if ($this->logoPath) {
            Storage::disk('public')->delete($this->logoPath);
            $settings->set('branding.logo', null, 'branding', 'string');
            $this->logoPath = null;
        }

        $this->dispatch('notify', type: 'success', message: __('Logo removed.'));
    }

    public function openStatusForm(?int $id = null): void
    {
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
        $this->statusForm->validate();

        SiteStatus::updateOrCreate(
            ['id' => $this->editingStatusId],
            [
                'name' => $this->statusForm->statusName,
                'color' => $this->statusForm->statusColor,
                'sort_order' => $this->statusForm->statusSortOrder,
            ]
        );

        $wasEditing = $this->editingStatusId !== null;

        $this->dispatch('close-modal-status-form');
        unset($this->siteStatuses);

        $this->dispatch('notify', type: 'success', message: $wasEditing
            ? __('Status updated.')
            : __('Status added.'));
    }

    public function deleteStatus(int $id): void
    {
        $status = SiteStatus::withCount('sites')->findOrFail($id);

        if ($status->sites_count > 0) {
            $this->dispatch('notify', type: 'error', message: __('Cannot delete ":name" — :count site(s) are assigned to it.', [
                'name' => $status->name,
                'count' => $status->sites_count,
            ]));

            return;
        }

        $name = $status->name;

        $status->delete();
        unset($this->siteStatuses);

        $this->dispatch('notify', type: 'success', message: __('Status ":name" deleted.', ['name' => $name]));
    }

    public function purgeMonitoringData(): void
    {
        UptimeCheck::query()->delete();
        UptimeIncident::query()->delete();

        $this->dispatch('notify', type: 'warning', message: __('Monitoring data has been purged.'));
    }

    public function render()
    {
        return view('livewire.settings.general-settings');
    }
}
