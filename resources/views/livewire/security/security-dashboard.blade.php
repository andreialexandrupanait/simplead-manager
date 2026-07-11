<div>
    <x-ui.page-header title="{{ __('Security Dashboard') }}" subtitle="{{ __('Cross-site security hardening overview') }}">
        @if(auth()->user()->isAdmin())
            <x-slot:actions>
                <a href="{{ route('security.presets') }}" wire:navigate>
                    <x-ui.button variant="secondary" size="sm">{{ __('Manage Presets') }}</x-ui.button>
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="dash-success" />

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="{{ __('Average Score') }}"
            :value="$this->avgScore ?? '—'"
            sublabel="{{ __('Across all configured sites') }}"
            icon="bar-chart-2"
            :color="$this->avgScore === null ? 'gray' : \App\Models\SecurityScan::scoreColor((int) $this->avgScore)"
        />
        <button type="button" wire:click="$set('scoreFilter', 'at_risk')" class="text-left">
            <x-ui.stat-card
                label="{{ __('At-Risk Sites') }}"
                :value="$this->atRiskSites"
                sublabel="{{ __('Score below 50 or not configured') }}"
                icon="alert-triangle"
                :color="$this->atRiskSites > 0 ? 'red' : 'green'"
                class="h-full cursor-pointer transition hover:border-accent-300 {{ $scoreFilter === 'at_risk' ? 'ring-2 ring-accent-400' : '' }}"
            />
        </button>
        <button type="button" wire:click="$set('scoreFilter', 'failed')" class="text-left">
            <x-ui.stat-card
                label="{{ __('Failed Settings') }}"
                :value="$this->failedSettingsCount"
                sublabel="{{ __('Could not be applied') }} · {{ $this->failedSitesCount }} {{ __('site(s)') }}"
                icon="x-circle"
                :color="$this->failedSettingsCount > 0 ? 'red' : 'green'"
                class="h-full cursor-pointer transition hover:border-accent-300 {{ $scoreFilter === 'failed' ? 'ring-2 ring-accent-400' : '' }}"
            />
        </button>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="[
                '' => __('All'),
                'at_risk' => __('At Risk').' ('.$this->atRiskSites.')',
                'good' => __('Good'),
                'excellent' => __('Excellent'),
                'failed' => __('Failed').' ('.$this->failedSitesCount.')',
            ]"
            :selected="$scoreFilter"
            wire="scoreFilter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search sites...') }}"
            class="ml-auto w-64"
        />
    </div>

    {{-- Sites Table --}}
    <x-ui.card class="overflow-hidden !p-0"
        x-data="{
            selected: [],
            get allSelected() {
                return this.selected.length === {{ $this->sites->count() }} && this.selected.length > 0; {{-- current page count --}}
            },
            toggleAll() {
                if (this.allSelected) {
                    this.selected = [];
                } else {
                    this.selected = [{{ $this->sites->pluck('id')->implode(',') }}];
                }
            }
        }"
    >
        {{-- Bulk action bar (desktop only — bulk selection is impractical on touch) --}}
        <div x-show="selected.length > 0" x-cloak class="hidden md:flex items-center gap-3 border-b border-gray-200 bg-accent-50/50 px-5 py-2.5">
            <span class="text-sm font-medium text-accent-700" x-text="selected.length + ' {{ __('selected') }}'"></span>
            <select wire:model="bulkPresetId" class="rounded-lg border-gray-300 text-xs focus:border-accent-500 focus:ring-accent-500">
                <option value="">{{ __('Choose preset...') }}</option>
                @foreach($this->presets as $preset)
                    <option value="{{ $preset->id }}">{{ $preset->name }}</option>
                @endforeach
            </select>
            <button
                @click="if (confirm('{{ __('Apply preset to') }} ' + selected.length + ' {{ __('site(s)?') }}')) { $wire.bulkApplyPreset(selected).then(() => selected = []) }"
                class="inline-flex items-center rounded-lg border border-accent-300 bg-white px-3 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 transition"
            >
                {{ __('Apply Preset') }}
            </button>
        </div>

        @if($this->sites->isEmpty())
            <x-ui.empty-state
                title="{{ __('No sites found') }}"
                description="{{ __('Add sites and configure security hardening to see them here.') }}"
                icon="shield-check"
            />
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-200">
                @foreach($this->sites as $site)
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('sites.security', $site) }}" class="flex items-center gap-2 min-w-0 hover:text-accent-600" wire:navigate>
                                <x-site-favicon :site="$site" class="h-5 w-5 shrink-0" />
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 truncate">{{ $site->name }}</p>
                                    <p class="text-xs text-gray-500 truncate">{{ $site->domain }}</p>
                                </div>
                            </a>
                            <div class="shrink-0 text-right">
                                @if($site->security_hardening_score !== null)
                                    @php
                                        $color = \App\Models\SecurityScan::scoreColor($site->security_hardening_score);
                                    @endphp
                                    <span class="inline-flex items-center gap-1 text-sm font-medium text-{{ $color }}-700">
                                        <span class="h-2 w-2 rounded-full bg-{{ $color }}-500"></span>
                                        {{ $site->security_hardening_score }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                {{ $site->enabled_settings_count }} {{ __('Settings') }}
                            </span>
                            @if($site->failed_settings_count > 0)
                                <a href="{{ route('sites.security', $site) }}#needs-attention"
                                   class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
                                    {{ $site->failed_settings_count }} {{ __('failed') }}
                                </a>
                            @endif
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">
                            {{ __('Last sync') }}: {{ $site->last_security_sync ? \Carbon\Carbon::parse($site->last_security_sync)->diffForHumans() : '—' }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <th class="px-3 py-2 w-10">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                            </th>
                            <x-ui.sortable-th column="name" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Site') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="security_hardening_score" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Score') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="enabled_settings_count" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Settings') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="last_security_sync" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Last Sync') }}</x-ui.sortable-th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->sites as $site)
                            <tr class="hover:bg-gray-50" :class="selected.includes({{ $site->id }}) && 'bg-accent-50/50'">
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $site->id }}" x-model.number="selected"
                                           class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('sites.security', $site) }}" class="flex items-center gap-2 hover:text-accent-600" wire:navigate>
                                            <x-site-favicon :site="$site" class="h-5 w-5" />
                                            <div>
                                                <p class="font-medium text-gray-900">{{ $site->name }}</p>
                                                <p class="text-xs text-gray-500">{{ $site->domain }}</p>
                                            </div>
                                        </a>
                                        @if($site->failed_settings_count > 0)
                                            <a href="{{ route('sites.security', $site) }}#needs-attention"
                                               class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-100">
                                                {{ $site->failed_settings_count }} {{ __('failed') }}
                                            </a>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    @if($site->security_hardening_score !== null)
                                        @php
                                            $color = \App\Models\SecurityScan::scoreColor($site->security_hardening_score);
                                        @endphp
                                        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-{{ $color }}-700">
                                            <span class="h-2 w-2 rounded-full bg-{{ $color }}-500"></span>
                                            {{ $site->security_hardening_score }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-xs text-gray-500">
                                    {{ $site->enabled_settings_count }} {{ __('enabled') }}
                                </td>
                                <td class="px-3 py-3 text-xs text-gray-500">
                                    {{ $site->last_security_sync ? \Carbon\Carbon::parse($site->last_security_sync)->diffForHumans() : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="px-5 py-3 border-t border-gray-200">
            {{ $this->sites->links() }}
        </div>
    </x-ui.card>
</div>
