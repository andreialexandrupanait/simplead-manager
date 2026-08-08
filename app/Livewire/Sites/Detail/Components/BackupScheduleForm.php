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

    /** The whole tree, nested. Alpine renders only the branches that are open. */
    public array $pickerTree = [];

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

        if ($this->pickerTree !== [] || $this->pickerLoading) {
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

            $this->pickerTree = $this->treeFrom(app(BackupBrowserService::class)->listContents($latest)['files']);
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

    /**
     * Turn the flat manifest into the tree a person can actually navigate.
     *
     * Folders first, then files, each level sorted by name — the order a file
     * manager uses, because that is the thing this is pretending to be. Folders
     * carry the total size of everything beneath them, which is what makes the
     * tree answer "what is making my backup big" on the way to answering "what do
     * I want to skip".
     *
     * The whole tree is built here and handed over once. Only the open branches
     * are ever rendered, so a site with forty thousand files costs one JSON
     * payload rather than forty thousand rows of DOM.
     *
     * @param  array<int, array{path: string, size: int}>  $files
     * @return list<array<string, mixed>>
     */
    private function treeFrom(array $files): array
    {
        $root = [];

        foreach ($files as $file) {
            $parts = explode('/', trim((string) $file['path'], '/'));
            $leaf = array_pop($parts);
            $node = &$root;
            $prefix = [];

            foreach ($parts as $segment) {
                $prefix[] = $segment;
                $node[$segment] ??= [
                    'type' => 'dir',
                    'name' => $segment,
                    'path' => implode('/', $prefix),
                    'bytes' => 0,
                    'children' => [],
                ];
                $node[$segment]['bytes'] += (int) $file['size'];
                $node = &$node[$segment]['children'];
            }

            $prefix[] = $leaf;
            $node[$leaf] = [
                'type' => 'file',
                'name' => $leaf,
                'path' => implode('/', $prefix),
                'bytes' => (int) $file['size'],
            ];
            unset($node);
        }

        return $this->sortLevel($root);
    }

    /**
     * Directories before files, each alphabetical — and the size formatted here
     * rather than in the view, because the view is a template and this is a
     * decision about what a number means.
     *
     * @param  array<string, mixed>  $level
     * @return list<array<string, mixed>>
     */
    private function sortLevel(array $level): array
    {
        $dirs = [];
        $files = [];

        foreach ($level as $node) {
            if ($node['type'] === 'dir') {
                $node['children'] = $this->sortLevel($node['children']);
                $dirs[] = $node;
            } else {
                $files[] = $node;
            }
        }

        usort($dirs, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return array_map(
            fn (array $n) => $n + ['size' => \App\Helpers\FormatHelper::bytes((int) $n['bytes'])],
            array_merge($dirs, $files),
        );
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
