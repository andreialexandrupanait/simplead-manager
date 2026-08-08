<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Components;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupBrowserService;
use App\Support\HumanError;
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

    /** The file picker: closed until asked for, because it reads a manifest out of storage. */
    public bool $pickerOpen = false;

    public bool $pickerLoading = false;

    public ?string $pickerError = null;

    /**
     * Only the folders that are open, and only their direct children.
     *
     * The first version of this held the entire tree in a public property, which
     * is Livewire state: three and a half megabytes serialised into every request
     * the component made afterwards, checkbox included. Livewire's payload limit
     * is one megabyte, so the second click on anything returned a 500.
     *
     * Keyed by folder path — '' is the root. It grows with what a person opens
     * and stops there.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $pickerLevels = [];

    /** @var list<string> */
    public array $pickerOpenPaths = [];

    /** The table picker, from the site's last database health check. */
    public bool $tablePickerOpen = false;

    public ?string $tablePickerError = null;

    /** @var list<array{name: string, bytes: int, rows: int, core: bool}> */
    public array $pickerTables = [];

    /**
     * Let a person pick the folder instead of typing a path from memory.
     *
     * The list comes from the newest backup's manifest, so this costs one small
     * read from storage and never touches the client's site. It is also the
     * honest source: what the last backup actually contained is exactly the set
     * you might want the next one to skip.
     *
     * Folders, not files. Exclusions are almost always a folder — a cache
     * directory, an uploads subtree, somebody's local dump — and a flat list of
     * forty thousand files is not a thing anyone can choose from. Each folder
     * carries what it is costing, which turns this from a path picker into the
     * answer to "what is making my backup so big".
     */
    public function openPicker(): void
    {
        $this->pickerOpen = true;

        if ($this->pickerLevels !== [] || $this->pickerLoading) {
            return;
        }

        $this->pickerLoading = true;
        $this->pickerError = null;

        try {
            /** @var Backup|null $latest */
            $latest = Backup::where('site_id', $this->site->id)
                ->where('status', BackupStatus::Completed)
                ->latest('created_at')
                ->first();

            if (! $latest instanceof Backup) {
                $this->pickerError = __('There is no completed backup to read a file list from yet.');

                return;
            }

            $this->pickerLevels[''] = $this->childrenOf('');
        } catch (\Throwable $e) {
            $this->pickerError = HumanError::from($e);
        } finally {
            $this->pickerLoading = false;
        }
    }

    /**
     * The site's tables, so a person can tick one instead of spelling it.
     *
     * Read from the last database health check rather than asked for live: that
     * check already runs on a schedule, already carries every table with its size
     * and row count, and asking again would be a request to a client's site for
     * something we were told an hour ago.
     *
     * Core tables are marked, not hidden. Excluding wp_posts is almost always a
     * mistake, and the honest way to prevent it is to say which ones the site
     * needs to run — not to remove them from the list and leave someone wondering
     * where they went.
     */
    public function openTablePicker(): void
    {
        $this->tablePickerOpen = true;

        if ($this->pickerTables !== []) {
            return;
        }

        $check = \App\Models\DatabaseHealthCheck::where('site_id', $this->site->id)
            ->latest('checked_at')
            ->first();

        if ($check === null) {
            $this->tablePickerError = __('No database check has run for this site yet, so there is no table list to choose from.');

            return;
        }

        $core = [
            'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
            'termmeta', 'terms', 'term_relationships', 'term_taxonomy',
            'usermeta', 'users',
        ];

        $rows = [];
        foreach ((array) ($check->tables_data ?? []) as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // The prefix is the site's, so match on the tail rather than assuming wp_.
            $isCore = false;
            foreach ($core as $suffix) {
                if (str_ends_with($name, '_'.$suffix) || $name === 'wp_'.$suffix) {
                    $isCore = true;
                    break;
                }
            }

            $rows[] = [
                'name' => $name,
                'bytes' => (int) (($table['data_size'] ?? 0) + ($table['index_size'] ?? 0)),
                'rows' => (int) ($table['rows'] ?? 0),
                'core' => $isCore,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        $this->pickerTables = array_map(
            fn (array $r) => $r + ['size' => \App\Helpers\FormatHelper::bytes($r['bytes'])],
            $rows,
        );
    }

    public function closeTablePicker(): void
    {
        $this->tablePickerOpen = false;
    }

    /** Add or remove a table from the skip list. */
    public function toggleTable(string $name): void
    {
        $current = $this->linesOf($this->exclude_tables);
        $current = in_array($name, $current, true)
            ? array_values(array_diff($current, [$name]))
            : [...$current, $name];

        $this->exclude_tables = implode("\n", $current);
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /** Tick or untick a path in the skip list. */
    public function togglePath(string $path): void
    {
        $current = $this->linesOf($this->exclude_paths);
        $current = in_array($path, $current, true)
            ? array_values(array_diff($current, [$path]))
            : [...$current, $path];

        $this->exclude_paths = implode("\n", $current);
    }

    /** Remove one entry, for the × on a chip. */
    public function removePath(string $path): void
    {
        $this->exclude_paths = implode("\n", array_values(array_diff($this->linesOf($this->exclude_paths), [$path])));
    }

    public function removeTable(string $name): void
    {
        $this->exclude_tables = implode("\n", array_values(array_diff($this->linesOf($this->exclude_tables), [$name])));
    }

    /**
     * A textarea, or a chip list, into the list the database keeps.
     *
     * Blank lines and stray whitespace are what people actually type; an empty
     * pattern is not a rule, and shipping one to thirty sites is worse than
     * dropping it here.
     *
     * @return list<string>
     */
    private function linesOf(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }

    /** @return list<string> */
    public function excludedPaths(): array
    {
        return $this->linesOf($this->exclude_paths);
    }

    /** @return list<string> */
    public function excludedTables(): array
    {
        return $this->linesOf($this->exclude_tables);
    }

    /** Open or close a folder, fetching its children the first time. */
    public function toggleFolder(string $path): void
    {
        if (in_array($path, $this->pickerOpenPaths, true)) {
            $this->pickerOpenPaths = array_values(array_diff($this->pickerOpenPaths, [$path]));

            return;
        }

        $this->pickerOpenPaths[] = $path;

        if (! array_key_exists($path, $this->pickerLevels)) {
            $this->pickerLevels[$path] = $this->childrenOf($path);
        }
    }

    /**
     * What sits directly inside this folder.
     *
     * One level at a time, on demand. The whole tree is thirty thousand nodes on
     * a real site and holding it in component state cost three and a half
     * megabytes on every subsequent request — over Livewire's limit, so the
     * second click returned a 500.
     *
     * The manifest read underneath is cached by BackupBrowserService for thirty
     * days (a backup's contents never change), so expanding a folder is a cache
     * hit and a filter, not a trip to storage.
     *
     * Directories before files, alphabetical within each — the order a file
     * manager uses, because that is the thing this is pretending to be. Folders
     * carry the total size of everything beneath them, which is what turns a
     * picker into an answer to "what is making my backup this big".
     *
     * @return list<array<string, mixed>>
     */
    private function childrenOf(string $prefix): array
    {
        /** @var Backup|null $latest */
        $latest = Backup::where('site_id', $this->site->id)
            ->where('status', BackupStatus::Completed)
            ->latest('created_at')
            ->first();

        if (! $latest instanceof Backup) {
            return [];
        }

        $depth = $prefix === '' ? 0 : substr_count($prefix, '/') + 1;
        $needle = $prefix === '' ? '' : $prefix.'/';

        $dirs = [];
        $files = [];

        foreach (app(BackupBrowserService::class)->listContents($latest)['files'] as $file) {
            $path = trim((string) $file['path'], '/');

            if ($needle !== '' && ! str_starts_with($path, $needle)) {
                continue;
            }

            $parts = explode('/', $path);
            if (count($parts) <= $depth) {
                continue;
            }

            $name = $parts[$depth];
            $childPath = $needle.$name;

            // A file sits at exactly this depth; anything deeper means a folder.
            if (count($parts) === $depth + 1) {
                $files[$name] = ['type' => 'file', 'name' => $name, 'path' => $childPath, 'bytes' => (int) $file['size']];

                continue;
            }

            $dirs[$name] ??= ['type' => 'dir', 'name' => $name, 'path' => $childPath, 'bytes' => 0];
            $dirs[$name]['bytes'] += (int) $file['size'];
        }

        uksort($dirs, 'strcasecmp');
        uksort($files, 'strcasecmp');

        return array_map(
            fn (array $n) => $n + ['size' => \App\Helpers\FormatHelper::bytes((int) $n['bytes'])],
            array_merge(array_values($dirs), array_values($files)),
        );
    }

    /**
     * The rows on screen: every open folder's children, flattened in order.
     *
     * Computed rather than stored, so it is never part of the payload.
     *
     * @return list<array<string, mixed>>
     */
    public function pickerRows(): array
    {
        $rows = [];

        $walk = function (string $prefix, int $depth) use (&$walk, &$rows): void {
            foreach ($this->pickerLevels[$prefix] ?? [] as $node) {
                $rows[] = $node + ['depth' => $depth, 'open' => in_array($node['path'], $this->pickerOpenPaths, true)];

                if ($node['type'] === 'dir' && in_array($node['path'], $this->pickerOpenPaths, true)) {
                    $walk($node['path'], $depth + 1);
                }
            }
        };

        $walk('', 0);

        return $rows;
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
