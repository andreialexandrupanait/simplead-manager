<?php

declare(strict_types=1);

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

    public bool $use_streaming = false;

    public string $retention_type = 'count';

    public int $retention_value = 10;

    public bool $backup_before_updates = false;

    /**
     * Exclusions, one per line, as the person typed them.
     *
     * Kept as text rather than an array because that is what the field is: a
     * textarea. The array lives in the database; converting at the boundary keeps
     * the "empty line the user left behind" problem in one place.
     */
    public string $exclude_paths = '';

    public string $exclude_tables = '';

    public bool $enable_incremental = false;

    public ?int $full_backup_day_of_week = 0;

    /**
     * A textarea into the list the database keeps.
     *
     * Blank lines and stray whitespace are what people actually type; the plugin
     * would treat an empty pattern as a rule matching nothing useful, so they are
     * dropped here rather than stored and shipped to thirty sites.
     *
     * @return list<string>
     */
    private function linesOf(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }

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
            $this->use_streaming = (bool) $config->use_streaming;
            $this->retention_type = $config->retention_type;
            $this->retention_value = $config->retention_value;
            $this->backup_before_updates = $config->backup_before_updates;
            $this->enable_incremental = ! empty($config->incremental_frequency);
            $this->full_backup_day_of_week = $config->full_backup_day_of_week ?? 0;
            $this->exclude_paths = implode("\n", (array) ($config->exclude_paths ?? []));
            $this->exclude_tables = implode("\n", (array) ($config->exclude_tables ?? []));
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
                'use_streaming' => $this->use_streaming,
                'retention_type' => $this->retention_type,
                'retention_value' => $this->retention_value,
                'backup_before_updates' => $this->backup_before_updates,
                'exclude_paths' => $this->linesOf($this->exclude_paths),
                'exclude_tables' => $this->linesOf($this->exclude_tables),
                'incremental_frequency' => ($this->enable_incremental && $this->type === 'full') ? 'daily' : null,
                'full_backup_day_of_week' => ($this->enable_incremental && $this->type === 'full') ? $this->full_backup_day_of_week : null,
                // Computed in the site's timezone above, stored in the application's — the column is
                // a wall clock and the dispatcher reads it on the application's. See
                // BackupConfig::asStoredRunTime().
                'next_backup_at' => $this->is_enabled ? BackupConfig::asStoredRunTime($nextBackupAt) : null,
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
