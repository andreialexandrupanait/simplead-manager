<?php

namespace App\Livewire\Sites\Detail\Components;

use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Livewire\Attributes\On;
use Livewire\Component;

class BackupScheduleForm extends Component
{
    public Site $site;

    public bool $is_enabled = false;
    public string $type = 'full';
    public string $frequency = 'daily';
    public string $time = '03:00';
    public ?int $day_of_week = 0;
    public ?int $day_of_month = 1;
    public string $timezone = 'UTC';
    public ?int $storage_destination_id = null;
    public string $retention_type = 'count';
    public int $retention_value = 10;
    public bool $backup_before_updates = false;
    public bool $enable_incremental = false;
    public ?int $full_backup_day_of_week = 0;

    #[On('open-schedule-form')]
    public function openModal(): void
    {
        $this->resetValidation();

        $config = $this->site->backupConfig;
        if ($config) {
            $this->is_enabled = $config->is_enabled;
            $this->type = $config->type;
            $this->frequency = $config->frequency;
            $this->time = $config->time;
            $this->day_of_week = $config->day_of_week ?? 0;
            $this->day_of_month = $config->day_of_month ?? 1;
            $this->timezone = $config->timezone;
            $this->storage_destination_id = $config->storage_destination_id;
            $this->retention_type = $config->retention_type;
            $this->retention_value = $config->retention_value;
            $this->backup_before_updates = $config->backup_before_updates;
            $this->enable_incremental = !empty($config->incremental_frequency);
            $this->full_backup_day_of_week = $config->full_backup_day_of_week ?? 0;
        }

        $this->dispatch('open-modal-schedule-form');
    }

    public function save(): void
    {
        $this->validate([
            'type' => 'required|in:full,database',
            'frequency' => 'required|in:daily,weekly,monthly',
            'time' => 'required|date_format:H:i',
            'timezone' => 'required|string',
            'retention_type' => 'required|in:count,days',
            'retention_value' => 'required|integer|min:1|max:365',
        ]);

        $nextBackupAt = $this->calculateNextBackup();

        BackupConfig::updateOrCreate(
            ['site_id' => $this->site->id],
            [
                'is_enabled' => $this->is_enabled,
                'type' => $this->type,
                'frequency' => $this->frequency,
                'time' => $this->time,
                'day_of_week' => $this->frequency === 'weekly' ? $this->day_of_week : null,
                'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
                'timezone' => $this->timezone,
                'storage_destination_id' => $this->storage_destination_id,
                'retention_type' => $this->retention_type,
                'retention_value' => $this->retention_value,
                'backup_before_updates' => $this->backup_before_updates,
                'incremental_frequency' => ($this->enable_incremental && $this->type === 'full') ? 'daily' : null,
                'full_backup_day_of_week' => ($this->enable_incremental && $this->type === 'full') ? $this->full_backup_day_of_week : null,
                'next_backup_at' => $this->is_enabled ? $nextBackupAt : null,
            ]
        );

        $this->dispatch('close-modal-schedule-form');
        $this->dispatch('schedule-saved');
    }

    protected function calculateNextBackup(): \Carbon\Carbon
    {
        [$hour, $minute] = explode(':', $this->time);
        $next = now()->setTimezone($this->timezone)->setTime((int) $hour, (int) $minute);

        if ($next->isPast()) {
            $next = match ($this->frequency) {
                'daily' => $next->addDay(),
                'weekly' => $next->addWeek(),
                'monthly' => $next->addMonth(),
                default => $next->addDay(),
            };
        }

        if ($this->frequency === 'weekly' && $this->day_of_week !== null) {
            $next->next((int) $this->day_of_week);
            $next->setTime((int) $hour, (int) $minute);
        }

        if ($this->frequency === 'monthly' && $this->day_of_month !== null) {
            $next->day(min((int) $this->day_of_month, $next->daysInMonth));
            if ($next->isPast()) {
                $next->addMonth();
                $next->day(min((int) $this->day_of_month, $next->daysInMonth));
            }
            $next->setTime((int) $hour, (int) $minute);
        }

        return $next->setTimezone('UTC');
    }

    public function getStorageDestinationsProperty()
    {
        return StorageDestination::where('is_active', true)->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.sites.detail.components.backup-schedule-form');
    }
}
