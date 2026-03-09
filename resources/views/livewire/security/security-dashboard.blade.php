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
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="Search sites..." />

        <div class="flex gap-2">
            @foreach(['' => 'All', 'at_risk' => 'At Risk', 'good' => 'Good', 'excellent' => 'Excellent'] as $key => $label)
                <button wire:click="$set('scoreFilter', '{{ $key }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium {{ $scoreFilter === $key ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Bulk Actions --}}
    @if(count($selectedSites) > 0)
        <x-ui.card class="mb-6">
            <div class="flex items-center gap-4">
                <span class="text-sm font-medium text-gray-700">{{ count($selectedSites) }} selected</span>
                <x-ui.select wire:model="bulkPresetId" class="text-sm w-48">
                    <option value="">Choose preset...</option>
                    @foreach($this->presets as $preset)
                        <option value="{{ $preset->id }}">{{ $preset->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.button size="sm" wire:click="bulkApplyPreset" wire:loading.attr="disabled"
                    wire:confirm="Apply this preset to {{ count($selectedSites) }} site(s)?">
                    Apply Preset
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Sites Table --}}
    <x-ui.card>
        @if($this->sites->isEmpty())
            <x-ui.empty-state
                title="No sites found"
                description="Add sites and configure security hardening to see them here."
                icon="shield"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <th class="pb-2 pr-4 w-8">
                                <x-ui.checkbox wire:model.live="selectedSites" value="all" />
                            </th>
                            <th class="pb-2 pr-4">Site</th>
                            <th class="pb-2 pr-4">Score</th>
                            <th class="pb-2 pr-4">Settings</th>
                            <th class="pb-2 pr-4">Pending</th>
                            <th class="pb-2 pr-4">Last Sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->sites as $site)
                            <tr>
                                <td class="py-2 pr-4">
                                    <x-ui.checkbox wire:model.live="selectedSites" value="{{ $site->id }}" />
                                </td>
                                <td class="py-2 pr-4">
                                    <a href="{{ route('sites.security', $site) }}" class="flex items-center gap-2 hover:text-purple-600" wire:navigate>
                                        <x-site-favicon :site="$site" class="h-5 w-5" />
                                        <div>
                                            <p class="font-medium text-gray-900">{{ $site->name }}</p>
                                            <p class="text-xs text-gray-500">{{ $site->domain }}</p>
                                        </div>
                                    </a>
                                </td>
                                <td class="py-2 pr-4">
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
                                <td class="py-2 pr-4 text-xs text-gray-500">
                                    {{ $site->enabled_settings_count }} enabled
                                </td>
                                <td class="py-2 pr-4">
                                    @if($site->pending_commands_count > 0)
                                        <span class="inline-flex rounded-full bg-yellow-50 px-2 py-0.5 text-xs font-medium text-yellow-700">
                                            {{ $site->pending_commands_count }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-xs text-gray-500">
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
