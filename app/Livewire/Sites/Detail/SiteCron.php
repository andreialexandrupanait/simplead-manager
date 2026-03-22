<?php

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteCron extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public ?array $cronData = null;

    public bool $loading = false;

    public string $search = '';

    // Enable modal state
    public ?string $enablingHook = null;

    public string $enableSchedule = 'daily';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function filteredCrons(): array
    {
        if (! $this->cronData) {
            return [];
        }

        $crons = $this->cronData['crons'] ?? [];

        if ($this->search) {
            $search = strtolower($this->search);
            $crons = array_filter($crons, fn ($cron) => str_contains(strtolower($cron['hook']), $search));
            $crons = array_values($crons);
        }

        return $crons;
    }

    #[Computed]
    public function schedules(): array
    {
        return $this->cronData['schedules'] ?? [];
    }

    public function loadCrons(): void
    {
        $this->loading = true;

        try {
            $api = new WordPressApiService($this->site);
            $this->cronData = $api->getCronList();
        } catch (\Exception $e) {
            Log::warning("Failed to load crons for site {$this->site->name}", [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Failed to load cron jobs: '.$e->getMessage());
            $this->cronData = null;
        }

        $this->loading = false;
        unset($this->filteredCrons, $this->schedules);
    }

    public function runCron(string $hook, array $args = []): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $api->runCron($hook, ! empty($args) ? $args : null);
            $this->dispatch('notify', type: 'success', message: "Cron hook '{$hook}' executed successfully.");
            $this->loadCrons();
        } catch (\Exception $e) {
            Log::warning("Failed to run cron hook {$hook} on site {$this->site->name}", [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Failed to run cron: '.$e->getMessage());
        }
    }

    public function disableCron(string $hook): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $api->disableCron($hook);
            $this->dispatch('notify', type: 'success', message: "Cron hook '{$hook}' disabled.");
            $this->loadCrons();
        } catch (\Exception $e) {
            Log::warning("Failed to disable cron hook {$hook} on site {$this->site->name}", [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Failed to disable cron: '.$e->getMessage());
        }
    }

    public function confirmEnableCron(string $hook): void
    {
        $this->enablingHook = $hook;
        $this->enableSchedule = 'daily';
        $this->dispatch('open-modal-enable-cron');
    }

    public function enableCron(): void
    {
        if (! $this->enablingHook) {
            return;
        }

        try {
            $api = new WordPressApiService($this->site);
            $api->enableCron($this->enablingHook, $this->enableSchedule);
            $this->dispatch('notify', type: 'success', message: "Cron hook '{$this->enablingHook}' enabled with schedule '{$this->enableSchedule}'.");
            $this->loadCrons();
        } catch (\Exception $e) {
            Log::warning("Failed to enable cron hook {$this->enablingHook} on site {$this->site->name}", [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Failed to enable cron: '.$e->getMessage());
        }

        $this->enablingHook = null;
        $this->dispatch('close-modal-enable-cron');
    }

    public function render()
    {
        return view('livewire.sites.detail.site-cron')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Cron Jobs',
            ]);
    }
}
