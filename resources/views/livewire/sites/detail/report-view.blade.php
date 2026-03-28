<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $report->title }}</h1>
            <p class="text-sm text-gray-500">
                {{ $report->period_start->format('M j, Y') }} — {{ $report->period_end->format('M j, Y') }}
                @if($report->file_path)
                    &middot; <a href="{{ route('reports.download', $report) }}" class="text-purple-600 hover:text-purple-800">Download PDF</a>
                @endif
            </p>
        </div>
        <a href="{{ route('sites.reports', $site) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to reports</a>
    </div>

    <div class="flex gap-6">
        {{-- Section nav --}}
        <nav class="w-48 flex-shrink-0">
            <div class="sticky top-4 space-y-1">
                @foreach($this->sections as $key => $label)
                    <button wire:click="setSection('{{ $key }}')"
                            class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                   {{ $activeSection === $key ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </nav>

        {{-- Content --}}
        <div class="flex-1 min-w-0">
            @php $data = $this->sectionData; @endphp

            @if(empty($data))
                <x-ui.card>
                    <p class="text-gray-500 text-sm">No data available for this section.</p>
                </x-ui.card>
            @elseif($activeSection === 'overview')
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @if(isset($data['updates']))
                        <x-ui.card>
                            <p class="text-xs text-gray-500">Updates Applied</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $data['updates']['count'] ?? 0 }}</p>
                        </x-ui.card>
                    @endif
                    @if(isset($data['uptime']))
                        <x-ui.card>
                            <p class="text-xs text-gray-500">Uptime</p>
                            <p class="text-2xl font-bold {{ ($data['uptime']['percentage'] ?? 0) >= 99 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ number_format($data['uptime']['percentage'] ?? 0, 2) }}%
                            </p>
                        </x-ui.card>
                    @endif
                    @if(isset($data['backups']))
                        <x-ui.card>
                            <p class="text-xs text-gray-500">Backups</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $data['backups']['successful'] ?? 0 }}</p>
                        </x-ui.card>
                    @endif
                    @if(isset($data['security']))
                        <x-ui.card>
                            <p class="text-xs text-gray-500">Security Score</p>
                            <p class="text-2xl font-bold {{ ($data['security']['score'] ?? 0) >= 80 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ $data['security']['score'] ?? 'N/A' }}
                            </p>
                        </x-ui.card>
                    @endif
                </div>
            @elseif($activeSection === 'uptime')
                <x-ui.card>
                    <h3 class="text-lg font-semibold mb-3">Uptime & Availability</h3>
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div><p class="text-xs text-gray-500">Uptime</p><p class="text-xl font-bold">{{ number_format($data['uptime_percentage'] ?? 0, 3) }}%</p></div>
                        <div><p class="text-xs text-gray-500">Incidents</p><p class="text-xl font-bold">{{ $data['incidents_count'] ?? 0 }}</p></div>
                        <div><p class="text-xs text-gray-500">Avg Response</p><p class="text-xl font-bold">{{ $data['avg_response_time'] ?? '—' }}ms</p></div>
                    </div>
                    @if(!empty($data['incidents']))
                        <h4 class="text-sm font-medium text-gray-700 mt-4 mb-2">Incidents</h4>
                        @foreach($data['incidents'] as $inc)
                            <div class="flex items-center justify-between py-2 border-b border-gray-100 text-sm">
                                <span class="text-gray-700">{{ $inc['cause'] ?? 'Unknown' }}</span>
                                <span class="text-gray-500">{{ $inc['duration'] ?? '' }}</span>
                            </div>
                        @endforeach
                    @endif
                </x-ui.card>
            @elseif($activeSection === 'updates')
                <x-ui.card>
                    <h3 class="text-lg font-semibold mb-3">Updates Applied</h3>
                    <div class="grid grid-cols-4 gap-3 mb-4">
                        <div><p class="text-xs text-gray-500">Total</p><p class="text-xl font-bold">{{ $data['total_count'] ?? 0 }}</p></div>
                        <div><p class="text-xs text-gray-500">Plugins</p><p class="text-xl font-bold">{{ $data['plugin_count'] ?? 0 }}</p></div>
                        <div><p class="text-xs text-gray-500">Themes</p><p class="text-xl font-bold">{{ $data['theme_count'] ?? 0 }}</p></div>
                        <div><p class="text-xs text-gray-500">Core</p><p class="text-xl font-bold">{{ $data['core_count'] ?? 0 }}</p></div>
                    </div>
                    @if(!empty($data['all_updates']))
                        <div class="border-t pt-3">
                            @foreach(array_slice($data['all_updates'], 0, 20) as $upd)
                                <div class="flex items-center justify-between py-1.5 text-sm">
                                    <span class="text-gray-700">{{ $upd['name'] ?? '' }}</span>
                                    <span class="text-gray-500">{{ $upd['from_version'] ?? '' }} → {{ $upd['to_version'] ?? '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @else
                {{-- Generic section: dump key-value pairs --}}
                <x-ui.card>
                    <h3 class="text-lg font-semibold mb-3">{{ $this->sections[$activeSection] ?? ucfirst($activeSection) }}</h3>
                    <div class="space-y-2">
                        @foreach($data as $key => $value)
                            @if(!is_array($value))
                                <div class="flex items-center justify-between py-1 border-b border-gray-50">
                                    <span class="text-sm text-gray-500">{{ str_replace('_', ' ', ucfirst($key)) }}</span>
                                    <span class="text-sm font-medium text-gray-900">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
