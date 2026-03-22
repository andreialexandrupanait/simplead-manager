<?php

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

    public function updatedEnabled(RetentionPolicyService $policy): void
    {
        $policy->setEnabled($this->enabled);
        $this->dispatch('notify', type: 'success', message: $this->enabled ? 'Automatic cleanup enabled.' : 'Automatic cleanup disabled.');
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
        $this->dispatch('notify', type: 'success', message: 'Retention settings saved.');
    }

    public function resetToDefaults(): void
    {
        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $this->days[$key] = $config['default'];
        }
    }

    public function runCleanupNow(): void
    {
        if ($this->hasRunningJobs) {
            $this->dispatch('notify', type: 'warning', message: 'Cleanup is already running.');

            return;
        }

        // Save current values first
        $this->save(app(RetentionPolicyService::class));

        $this->dispatchTrackedJob(
            'cleanup',
            new RetentionCleanup('manual'),
            'Starting retention cleanup...'
        );

        $this->dispatch('notify', type: 'info', message: 'Retention cleanup started.');
    }

    public function formatOldest(?string $oldest): string
    {
        if (! $oldest) {
            return 'No data';
        }

        try {
            $date = Carbon::parse($oldest);
            $daysAgo = (int) $date->diffInDays(now());

            if ($daysAgo === 0) {
                return 'Today';
            }

            return "{$daysAgo}d ago";
        } catch (\Exception) {
            return 'Unknown';
        }
    }

    public function render()
    {
        return view('livewire.settings.data-retention-settings')
            ->layout('components.layouts.app', ['title' => 'Data Retention']);
    }
}
