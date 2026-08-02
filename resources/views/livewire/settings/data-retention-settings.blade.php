{{-- Retention used to open with a second page header two-thirds down the tab —
     icon chip, h2, subtitle and right-aligned actions — competing with the real
     one at the top. The actions moved into the section header; the page has one
     heading again. The eight per-category cards lost their eight differently
     coloured icon chips for the same reason. --}}
<div @if($hasRunningJobs) wire:poll.3s="checkJobProgress" @endif class="space-y-6">

    <x-settings.section
        id="retention"
        :title="__('Data Retention')"
        :subtitle="__('How long operational data is kept before it is cleaned up automatically.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="ghost" size="sm" type="button"
                wire:click="resetToDefaults"
                wire:target="resetToDefaults"
                wire:loading.attr="disabled"
            >
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="resetToDefaults" />
                {{ __('Reset to Defaults') }}
            </x-ui.button>
            <x-ui.button
                variant="secondary" size="sm" type="button"
                wire:click="runCleanupNow"
                wire:target="runCleanupNow"
                wire:loading.attr="disabled"
                wire:confirm="{{ __('Run retention cleanup now with current settings? This will save your settings and delete data older than the configured thresholds.') }}"
            >
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="runCleanupNow" />
                {{ __('Run Now') }}
            </x-ui.button>
        </x-slot:actions>

        <x-ui.job-progress job-key="cleanup" :jobs="$trackedJobs" :title="__('Running retention cleanup...')" />

        {{-- Master switch. Was `wire:model.live` on x-ui.toggle, which renders a
             <button> whose .value is the empty string — wire:model had nothing
             to read, so the control never moved. $toggle round-trips through
             $set, so updatedEnabled() still fires and still persists. --}}
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">{{ __('Automatic Cleanup') }}</p>
                <p class="text-xs text-gray-500">{{ __('When enabled, old data is automatically cleaned up daily at 3:00 AM.') }}</p>
            </div>
            <x-ui.toggle
                :enabled="$enabled"
                wire:click="$toggle('enabled')"
                aria-label="{{ __('Automatic Cleanup') }}"
            />
        </div>

        <form wire:submit="save" class="mt-4">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach($this->categories as $key => $category)
                    <div wire:key="retention-{{ $key }}" class="rounded-lg border border-gray-100 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $category['label'] }}</p>
                                {{-- `tables` is already a list of labels — the
                                     old array_column() over strings returned []
                                     and every card's subtitle was blank. --}}
                                <p class="text-xs text-gray-500">{{ implode(', ', $category['tables']) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.input
                                    type="number"
                                    id="retention-days-{{ $key }}"
                                    wire:model="days.{{ $key }}"
                                    min="{{ $category['min'] }}"
                                    max="{{ $category['max'] }}"
                                    class="w-20 text-center"
                                    aria-label="{{ __('Retention in days for :category', ['category' => $category['label']]) }}"
                                />
                                <span class="text-sm text-gray-500">{{ __('days') }}</span>
                            </div>
                        </div>

                        @if(isset($this->categoryStats[$key]))
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                @foreach($this->categoryStats[$key] as $stat)
                                    <span class="text-xs text-gray-500">
                                        {{ $stat['label'] }}:
                                        <span class="text-gray-700">~{{ number_format($stat['total_estimate']) }}</span>
                                        @if($stat['oldest'])
                                            <span class="text-gray-500">&middot; {{ __('oldest') }} {{ $this->formatOldest($stat['oldest']) }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <p class="mt-2 text-xs text-gray-500">
                            {{ __('Range') }}: {{ $category['min'] }}&ndash;{{ $category['max'] }}
                            &middot; {{ __('Default') }}: {{ $category['default'] }}
                        </p>

                        @error("days.{$key}")
                            <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </form>

        {{-- Outside the <form>: the save-bar button has no type, so inside one
             it would submit and call save() on the same click. --}}
        <x-ui.save-bar
            target="save"
            :message="__('Thresholds apply at the next cleanup run.')"
            :label="__('Save Settings')"
        />
    </x-settings.section>

    {{-- Site backups. A separate section from the tables above because the stakes are not the same:
         a pruned log row is an inconvenience, a deleted restore point is gone. Whether deletion is
         real used to be an environment variable, which is precisely why it was never switched on —
         turning it on meant editing the deployment, so retention ran nightly, decided what to
         remove and removed nothing, while a terabyte accumulated. --}}
    <x-settings.section
        id="backup-retention"
        :title="__('Site Backups')"
        :subtitle="__('How many restore points each site keeps, and whether older ones are actually deleted.')"
    >
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">{{ __('Delete old backups') }}</p>
                <p class="text-xs text-gray-500">
                    {{ __('When off, retention still runs and reports what it would remove — but deletes nothing.') }}
                </p>
            </div>
            <x-ui.toggle
                :enabled="$backupDeletesEnabled"
                wire:click="$toggle('backupDeletesEnabled')"
                aria-label="{{ __('Delete old backups') }}"
            />
        </div>

        <form wire:submit="saveBackupRetention" class="mt-4">
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ __('Restore points kept per site') }}</p>
                        <p class="text-xs text-gray-500">
                            {{ __('Counted in restore points, not days, so the number you set is the number you get regardless of how often backups ran.') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-ui.input
                            type="number"
                            id="backup-keep-per-site"
                            wire:model="backupKeepPerSite"
                            min="{{ \App\Services\Backup\BackupRetentionSettings::MIN_KEEP }}"
                            max="{{ \App\Services\Backup\BackupRetentionSettings::MAX_KEEP }}"
                            class="w-20 text-center"
                            aria-label="{{ __('Restore points kept per site') }}"
                        />
                        <span class="text-sm text-gray-500">{{ __('backups') }}</span>
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                    <span class="text-xs text-gray-500">
                        {{ __('Sites with backups') }}:
                        <span class="text-gray-700">{{ number_format($this->backupStats['sites']) }}</span>
                    </span>
                    <span class="text-xs text-gray-500">
                        {{ __('Restore points stored') }}:
                        <span class="text-gray-700">{{ number_format($this->backupStats['backups']) }}</span>
                    </span>
                    @if($this->backupStats['over_limit'] > 0)
                        <span class="text-xs text-gray-500">
                            {{ __('Above the limit') }}:
                            <span class="text-gray-700">{{ number_format($this->backupStats['over_limit']) }}</span>
                        </span>
                    @endif
                </div>
            </div>

            <x-ui.save-bar
                target="saveBackupRetention"
                :message="__('Applies to every site, and takes effect at the next backup.')"
                :label="__('Save')"
            />
        </form>
    </x-settings.section>

    @if($this->lastRunResult)
        <x-settings.section
            :title="__('Last Cleanup Run')"
            :subtitle="__('What the most recent cleanup deleted.')"
        >
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @if($this->lastRunAt)
                    <x-ui.stat-box :label="__('Date')">
                        <span class="text-sm">{{ \Carbon\Carbon::parse($this->lastRunAt)->format('M d, Y H:i') }}</span>
                    </x-ui.stat-box>
                @endif
                <x-ui.stat-box :label="__('Trigger')">
                    <span class="text-sm capitalize">{{ $this->lastRunResult['trigger'] ?? __('scheduled') }}</span>
                </x-ui.stat-box>
                <x-ui.stat-box :label="__('Duration')">
                    <span class="text-sm">{{ $this->lastRunResult['duration_seconds'] ?? 0 }}s</span>
                </x-ui.stat-box>
                <x-ui.stat-box :label="__('Total deleted')">
                    <span class="text-sm">{{ number_format($this->lastRunResult['total_deleted'] ?? 0) }}</span>
                </x-ui.stat-box>
                @if($this->lastRunResult['expired_backups'] ?? 0)
                    <x-ui.stat-box :label="__('Expired backups')">
                        <span class="text-sm">{{ $this->lastRunResult['expired_backups'] }}</span>
                    </x-ui.stat-box>
                @endif
            </div>

            @if($this->lastRunResult['hit_deadline'] ?? false)
                <x-ui.alert type="warning" class="mt-3">
                    {{ __('Hit time deadline — will continue next run') }}
                </x-ui.alert>
            @endif

            @if(! empty($this->lastRunResult['categories']))
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach($this->lastRunResult['categories'] as $catKey => $catResult)
                            @php
                                $catLabel = \App\Services\RetentionPolicyService::CATEGORIES[$catKey]['label'] ?? $catKey;
                                $deleted = $catResult['deleted'] ?? 0;
                            @endphp
                            <x-ui.info-row :label="$catLabel">
                                <span class="{{ $deleted > 0 ? 'font-medium text-accent-700' : 'text-gray-500' }}">
                                    {{ number_format($deleted) }}
                                </span>
                            </x-ui.info-row>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-settings.section>
    @endif
</div>
