@php
    use App\Services\RetentionPolicyService;

    $categoryIcons = [
        'uptime' => ['icon' => 'activity', 'bg' => 'bg-green-50', 'ring' => 'ring-green-200', 'text' => 'text-green-600'],
        'performance' => ['icon' => 'trending-up', 'bg' => 'bg-orange-50', 'ring' => 'ring-orange-200', 'text' => 'text-orange-600'],
        'security' => ['icon' => 'shield', 'bg' => 'bg-blue-50', 'ring' => 'ring-blue-200', 'text' => 'text-blue-600'],
        'analytics' => ['icon' => 'bar-chart-2', 'bg' => 'bg-purple-50', 'ring' => 'ring-purple-200', 'text' => 'text-purple-600'],
        'activity_logs' => ['icon' => 'file-text', 'bg' => 'bg-gray-100', 'ring' => 'ring-gray-200', 'text' => 'text-gray-600'],
        'notification_logs' => ['icon' => 'bell', 'bg' => 'bg-indigo-50', 'ring' => 'ring-indigo-200', 'text' => 'text-indigo-600'],
        'system' => ['icon' => 'database', 'bg' => 'bg-yellow-50', 'ring' => 'ring-yellow-200', 'text' => 'text-yellow-600'],
        'security_hardening' => ['icon' => 'shield-check', 'bg' => 'bg-red-50', 'ring' => 'ring-red-200', 'text' => 'text-red-600'],
    ];
    $defaultIcon = ['icon' => 'clock', 'bg' => 'bg-gray-100', 'ring' => 'ring-gray-200', 'text' => 'text-gray-600'];
@endphp
<div>
    @include('livewire.settings.partials.settings-tabs')

    <div @if($hasRunningJobs) wire:poll.3s="checkJobProgress" @endif>

        {{-- Job Progress --}}
        <x-ui.job-progress job-key="cleanup" :jobs="$trackedJobs" title="Running retention cleanup..." />

        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-50 shadow-sm ring-1 ring-purple-200">
                    <x-icons.clock class="h-5 w-5 text-purple-600" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Data Retention') }}</h2>
                    <p class="mt-0.5 text-sm text-gray-500">{{ __('Configure how long operational data is kept before automatic cleanup.') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" wire:click="resetToDefaults" class="text-sm text-gray-500 hover:text-gray-700">
                    {{ __('Reset to Defaults') }}
                </button>
                <x-ui.button wire:click="runCleanupNow" variant="secondary" size="sm" wire:loading.attr="disabled" wire:target="runCleanupNow"
                    wire:confirm="{{ __('Run retention cleanup now with current settings? This will save your settings and delete data older than the configured thresholds.') }}">
                    <span wire:loading.remove wire:target="runCleanupNow">{{ __('Run Now') }}</span>
                    <span wire:loading wire:target="runCleanupNow">{{ __('Starting...') }}</span>
                </x-ui.button>
            </div>
        </div>

        {{-- Global Toggle --}}
        <x-ui.card class="mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-green-50 shadow-sm ring-1 ring-green-200">
                        <x-icons.refresh-cw class="h-5 w-5 text-green-600" />
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-900">{{ __('Automatic Cleanup') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('When enabled, old data is automatically cleaned up daily at 3:00 AM.') }}</p>
                    </div>
                </div>
                <x-ui.toggle wire:model.live="enabled" :enabled="$enabled" />
            </div>
        </x-ui.card>

        {{-- Category Cards Grid --}}
        <form wire:submit="save">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->categories as $key => $category)
                    @php
                        $iconConfig = $categoryIcons[$key] ?? $defaultIcon;
                    @endphp
                    <x-ui.card>
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $iconConfig['bg'] }} shadow-sm ring-1 {{ $iconConfig['ring'] }}">
                                    <x-dynamic-component :component="'icons.' . $iconConfig['icon']" class="h-5 w-5 {{ $iconConfig['text'] }}" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">{{ $category['label'] }}</h3>
                                    <p class="text-xs text-gray-500">{{ implode(', ', array_column($category['tables'], 'label')) }}</p>
                                </div>
                            </div>
                            <x-ui.badge variant="purple">{{ $days[$key] ?? $category['default'] }} {{ __('days') }}</x-ui.badge>
                        </div>

                        {{-- Stats --}}
                        @if(isset($this->categoryStats[$key]))
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                                @foreach($this->categoryStats[$key] as $stat)
                                    <span class="text-xs text-gray-400">
                                        {{ $stat['label'] }}:
                                        <span class="text-gray-600">~{{ number_format($stat['total_estimate']) }}</span>
                                        @if($stat['oldest'])
                                            <span class="text-gray-400">&middot; {{ __('oldest') }} {{ $this->formatOldest($stat['oldest']) }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Days Input in Footer --}}
                        <div class="mt-4 border-t border-gray-100 pt-4">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-400">
                                    {{ __('Range') }}: {{ $category['min'] }}&ndash;{{ $category['max'] }} &middot; {{ __('Default') }}: {{ $category['default'] }}
                                </span>
                                <div class="flex items-center gap-2">
                                    <x-ui.input
                                        type="number"
                                        wire:model="days.{{ $key }}"
                                        min="{{ $category['min'] }}"
                                        max="{{ $category['max'] }}"
                                        class="w-20 text-center text-sm"
                                    />
                                    <span class="text-sm text-gray-500">{{ __('days') }}</span>
                                </div>
                            </div>
                            @error("days.{$key}")
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Save Settings') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </x-ui.button>
            </div>
        </form>

        {{-- Last Run Results --}}
        @if($this->lastRunResult)
            <div class="mt-8">
                <x-ui.card>
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 shadow-sm ring-1 ring-gray-200">
                            <x-icons.refresh-cw class="h-5 w-5 text-gray-600" />
                        </div>
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('Last Cleanup Run') }}</h3>
                    </div>

                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 mb-4">
                        @if($this->lastRunAt)
                            <span>
                                {{ __('Date') }}: <span class="font-medium">{{ \Carbon\Carbon::parse($this->lastRunAt)->format('M d, Y H:i') }}</span>
                            </span>
                        @endif
                        <span>
                            {{ __('Trigger') }}: <span class="font-medium capitalize">{{ $this->lastRunResult['trigger'] ?? __('scheduled') }}</span>
                        </span>
                        <span>
                            {{ __('Duration') }}: <span class="font-medium">{{ $this->lastRunResult['duration_seconds'] ?? 0 }}s</span>
                        </span>
                        <span>
                            {{ __('Total deleted') }}: <span class="font-medium">{{ number_format($this->lastRunResult['total_deleted'] ?? 0) }}</span>
                        </span>
                        @if($this->lastRunResult['expired_backups'] ?? 0)
                            <span>
                                {{ __('Expired backups') }}: <span class="font-medium">{{ $this->lastRunResult['expired_backups'] }}</span>
                            </span>
                        @endif
                        @if($this->lastRunResult['hit_deadline'] ?? false)
                            <span class="text-amber-600 font-medium">{{ __('Hit time deadline — will continue next run') }}</span>
                        @endif
                    </div>

                    @if(!empty($this->lastRunResult['categories']))
                        <div class="border-t border-gray-100 pt-3">
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach($this->lastRunResult['categories'] as $catKey => $catResult)
                                    @php
                                        $catLabel = RetentionPolicyService::CATEGORIES[$catKey]['label'] ?? $catKey;
                                    @endphp
                                    <div class="flex items-center justify-between rounded-lg px-3 py-2 {{ ($catResult['deleted'] ?? 0) > 0 ? 'bg-purple-50' : 'bg-gray-50' }}">
                                        <span class="text-xs text-gray-600">{{ $catLabel }}</span>
                                        <span class="text-xs font-medium {{ ($catResult['deleted'] ?? 0) > 0 ? 'text-purple-700' : 'text-gray-400' }}">
                                            {{ number_format($catResult['deleted'] ?? 0) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            </div>
        @endif

    </div>
</div>
