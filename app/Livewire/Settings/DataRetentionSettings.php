<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\RetentionCleanup;
use App\Livewire\Traits\WithJobTracking;
use App\Services\RetentionPolicyService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DataRetentionSettings extends Component
{
    use WithJobTracking;

    public bool $enabled = true;

    public array $days = [];

    public function mount(RetentionPolicyService $policy): void
    {
        $this->enabled = $policy->isEnabled();

        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $this->days[$key] = $policy->getDays($key);
        }

        $this->initJobTracking();
    }

    protected function jobTrackingKeys(): array
    {
        return ['cleanup' => RetentionCleanup::JOB_KEY];
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->lastRunResult, $this->lastRunAt);
    }

    #[Computed]
    public function categories(): array
    {
        $result = [];

        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $result[$key] = [
                'label' => $config['label'],
                'default' => $config['default'],
                'min' => $config['min'],
                'max' => $config['max'],
                'tables' => array_column($config['tables'], 'label'),
            ];
        }

        return $result;
    }

    #[Computed]
    public function lastRunResult(): ?array
    {
        return app(RetentionPolicyService::class)->getLastRunResult();
    }

    #[Computed]
    public function lastRunAt(): ?string
    {
        return app(RetentionPolicyService::class)->getLastRunAt();
    }

    #[Computed]
    public function categoryStats(): array
    {
        $policy = app(RetentionPolicyService::class);
        $stats = [];

        foreach (array_keys(RetentionPolicyService::CATEGORIES) as $key) {
            $stats[$key] = $policy->getCategoryStats($key);
        }

        return $stats;
    }

    /**
     * Side effect of the master switch. Still an `updated` hook: the control is
     * now `wire:click="$toggle('enabled')"` (x-ui.toggle renders a <button>, so
     * wire:model had no value to read), and $toggle round-trips through $set —
     * which runs the updating/updated hooks exactly as wire:model.live did.
     */
    public function updatedEnabled(RetentionPolicyService $policy): void
    {
        $policy->setEnabled($this->enabled);
        $this->dispatch('notify', type: 'success', message: $this->enabled
            ? __('Automatic cleanup enabled.')
            : __('Automatic cleanup disabled.'));
    }

    public function save(RetentionPolicyService $policy): void
    {
        $rules = ['enabled' => 'required|boolean'];

        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $rules["days.{$key}"] = "required|integer|min:{$config['min']}|max:{$config['max']}";
        }

        $this->validate($rules);

        $policy->setEnabled($this->enabled);

        foreach ($this->days as $key => $value) {
            $policy->setDays($key, (int) $value);
        }

        unset($this->categoryStats);
        $this->dispatch('notify', type: 'success', message: __('Retention settings saved.'));
    }

    /**
     * Fills the inputs with the shipped defaults. It deliberately does not
     * persist — that stays a Save away, same as any other edit — but it used to
     * do so in complete silence, so the button looked broken.
     */
    public function resetToDefaults(): void
    {
        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $this->days[$key] = $config['default'];
        }

        $this->resetValidation();

        $this->dispatch('notify', type: 'info', message: __('Defaults restored in the form — press Save to apply them.'));
    }

    public function runCleanupNow(): void
    {
        if ($this->hasRunningJobs) {
            $this->dispatch('notify', type: 'warning', message: __('Cleanup is already running.'));

            return;
        }

        // Save current values first
        $this->save(app(RetentionPolicyService::class));

        $this->dispatchTrackedJob(
            'cleanup',
            new RetentionCleanup('manual'),
            'Starting retention cleanup...'
        );

        $this->dispatch('notify', type: 'info', message: __('Retention cleanup started.'));
    }

    public function formatOldest(?string $oldest): string
    {
        if (! $oldest) {
            return (string) __('No data');
        }

        try {
            $date = Carbon::parse($oldest);
            $daysAgo = (int) $date->diffInDays(now());

            if ($daysAgo === 0) {
                return (string) __('Today');
            }

            return (string) __(':days days ago', ['days' => $daysAgo]);
        } catch (\Exception) {
            return (string) __('Unknown');
        }
    }

    public function render()
    {
        return view('livewire.settings.data-retention-settings');
    }
}
