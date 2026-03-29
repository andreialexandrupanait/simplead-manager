<div>
    <x-ui.page-header title="Security Dashboard" subtitle="Cross-site security hardening overview" />

    <x-ui.flash-alert type="success" key="dash-success" />

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="Average Score"
            :value="$this->avgScore ?? '—'"
            description="Across all configured sites"
        />
        <x-ui.stat-card
            label="At-Risk Sites"
            :value="$this->atRiskSites"
            description="Score below 50 or not configured"
        />
        <x-ui.stat-card
            label="Pending Commands"
            :value="$this->pendingCommandsCount"
            description="Waiting for agents to pick up"
        />
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['' => 'All', 'at_risk' => 'At Risk', 'good' => 'Good', 'excellent' => 'Excellent']"
            :selected="$scoreFilter"
            wire="scoreFilter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="Search sites..."
            class="ml-auto w-64"
        />
    </div>

    {{-- Sites Table --}}
    <x-ui.card class="overflow-hidden !p-0"
        x-data="{
            selected: [],
            get allSelected() {
                return this.selected.length === {{ $this->sites->count() }} && this.selected.length > 0;
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
        {{-- Bulk action bar --}}
        <div x-show="selected.length > 0" x-cloak class="flex items-center gap-3 border-b border-gray-200 bg-purple-50/50 px-5 py-2.5">
            <span class="text-sm font-medium text-purple-700" x-text="selected.length + ' selected'"></span>
            <select wire:model="bulkPresetId" class="rounded-lg border-gray-300 text-xs focus:border-purple-500 focus:ring-purple-500">
                <option value="">Choose preset...</option>
                @foreach($this->presets as $preset)
                    <option value="{{ $preset->id }}">{{ $preset->name }}</option>
                @endforeach
            </select>
            <button
                @click="if (confirm('Apply preset to ' + selected.length + ' site(s)?')) { $wire.bulkApplyPreset(selected).then(() => selected = []) }"
                class="inline-flex items-center rounded-lg border border-purple-300 bg-white px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-50 transition"
            >
                Apply Preset
            </button>
        </div>

        @if($this->sites->isEmpty())
            <x-ui.empty-state
                title="No sites found"
                description="Add sites and configure security hardening to see them here."
                icon="shield-check"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <th class="px-3 py-2 w-10">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                            </th>
                            <x-ui.sortable-th column="name" :sortBy="$sortBy" :sortDir="$sortDir">Site</x-ui.sortable-th>
                            <x-ui.sortable-th column="security_hardening_score" :sortBy="$sortBy" :sortDir="$sortDir">Score</x-ui.sortable-th>
                            <th class="px-3 py-2">Settings</th>
                            <th class="px-3 py-2">Pending</th>
                            <th class="px-3 py-2">Last Sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->sites as $site)
                            <tr class="hover:bg-gray-50" :class="selected.includes({{ $site->id }}) && 'bg-purple-50/50'">
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $site->id }}" x-model.number="selected"
                                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                                </td>
                                <td class="px-3 py-3">
                                    <a href="{{ route('sites.security', $site) }}" class="flex items-center gap-2 hover:text-purple-600" wire:navigate>
                                        <x-site-favicon :site="$site" class="h-5 w-5" />
                                        <div>
                                            <p class="font-medium text-gray-900">{{ $site->name }}</p>
                                            <p class="text-xs text-gray-500">{{ $site->domain }}</p>
                                        </div>
                                    </a>
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
                                    {{ $site->enabled_settings_count }} enabled
                                </td>
                                <td class="px-3 py-3">
                                    @if($site->pending_commands_count > 0)
                                        <span class="inline-flex rounded-full bg-yellow-50 px-2 py-0.5 text-xs font-medium text-yellow-700">
                                            {{ $site->pending_commands_count }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">0</span>
                                    @endif
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
    </x-ui.card>
</div>
