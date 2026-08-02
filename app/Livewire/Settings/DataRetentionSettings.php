<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\BackupStatus;
use App\Jobs\RetentionCleanup;
use App\Livewire\Traits\WithJobTracking;
use App\Models\Backup;
use App\Services\Backup\BackupRetentionSettings;
use App\Services\RetentionPolicyService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DataRetentionSettings extends Component
{
    use WithJobTracking;

    public bool $enabled = true;

    public array $days = [];

    /** Whether old BACKUPS (the archives, not the log tables) are actually deleted. */
    public bool $backupDeletesEnabled = false;

    /** How many restore points each site keeps. */
    public int $backupKeepPerSite = BackupRetentionSettings::DEFAULT_KEEP;

    public function mount(RetentionPolicyService $policy, BackupRetentionSettings $backups): void
    {
        $this->enabled = $policy->isEnabled();

        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $this->days[$key] = $policy->getDays($key);
        }

        $this->backupDeletesEnabled = $backups->deletesEnabled();
        $this->backupKeepPerSite = $backups->keepPerSite();

        $this->initJobTracking();
    }

    /**
     * How much the current backup policy is holding, so the number above is not abstract.
     *
     * @return array{sites: int, backups: int, over_limit: int}
     */
    #[Computed]
    public function backupStats(): array
    {
        $perSite = Backup::query()
            ->where('status', BackupStatus::Completed)
            ->selectRaw('site_id, count(*) as total')
            ->groupBy('site_id')
            ->pluck('total', 'site_id');

        return [
            'sites' => $perSite->count(),
            'backups' => (int) $perSite->sum(),
            'over_limit' => (int) $perSite->sum(fn (int $n): int => max(0, $n - $this->backupKeepPerSite)),
        ];
    }

    /**
     * Side effect of the backup switch, mirroring updatedEnabled(): x-ui.toggle renders a button,
     * so the control is a $toggle round trip rather than wire:model.
     */
    public function updatedBackupDeletesEnabled(BackupRetentionSettings $backups): void
    {
        $backups->setDeletesEnabled($this->backupDeletesEnabled);
        unset($this->backupStats);

        $this->dispatch('notify', type: 'success', message: $this->backupDeletesEnabled
            ? __('Old backups will now be deleted automatically.')
            : __('Backup deletion paused — retention will only report what it would remove.'));
    }

    public function saveBackupRetention(BackupRetentionSettings $backups): void
    {
        $this->validate([
            'backupKeepPerSite' => 'required|integer|min:'.BackupRetentionSettings::MIN_KEEP.'|max:'.BackupRetentionSettings::MAX_KEEP,
        ]);

        $changed = $backups->setKeepPerSite($this->backupKeepPerSite);
        $this->backupKeepPerSite = $backups->keepPerSite();
        unset($this->backupStats);

        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{0}Backup retention saved.|[1,*]Backup retention saved and applied to :count site schedule(s).',
            $changed,
            ['count' => $changed],
        ));
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
