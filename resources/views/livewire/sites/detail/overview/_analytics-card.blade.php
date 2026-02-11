<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100">
                <svg class="h-5 w-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Analytics</h3>
        </div>
        <a href="{{ route('sites.analytics', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
            View Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        {{-- Period Selector --}}
        <div class="mb-4 flex gap-2 border-b border-gray-100 pb-3">
            <button
                wire:click="setAnalyticsPeriod('1d')"
                class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ $analyticsPeriod === '1d' ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}"
            >
                Yesterday
            </button>
            <button
                wire:click="setAnalyticsPeriod('7d')"
                class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ $analyticsPeriod === '7d' ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}"
            >
                7 Days
            </button>
            <button
                wire:click="setAnalyticsPeriod('28d')"
                class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ $analyticsPeriod === '28d' ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}"
            >
                30 Days
            </button>
            <button
                wire:click="setAnalyticsPeriod('90d')"
                class="rounded-lg px-3 py-1.5 text-xs font-medium transition {{ $analyticsPeriod === '90d' ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}"
            >
                90 Days
            </button>
        </div>

        @if($this->analyticsData)
            @php
                $data = $this->analyticsData;
            @endphp

            {{-- Metrics Grid --}}
            <div class="space-y-3">
                {{-- Total Users --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-4">
                    <div>
                        <div class="text-sm text-gray-600">Total Users</div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            {{ number_format($data['users'] ?? 0) }}
                        </div>
                    </div>
                    @if(isset($data['users_change']))
                        <div class="flex items-center gap-1 text-sm {{ $data['users_change'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                @if($data['users_change'] >= 0)
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                                @else
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                @endif
                            </svg>
                            {{ abs($data['users_change']) }}%
                        </div>
                    @endif
                </div>

                {{-- Sessions --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-4">
                    <div>
                        <div class="text-sm text-gray-600">Sessions</div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            {{ number_format($data['sessions'] ?? 0) }}
                        </div>
                    </div>
                    @if(isset($data['sessions_change']))
                        <div class="flex items-center gap-1 text-sm {{ $data['sessions_change'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                @if($data['sessions_change'] >= 0)
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                                @else
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                @endif
                            </svg>
                            {{ abs($data['sessions_change']) }}%
                        </div>
                    @endif
                </div>

                {{-- Pageviews --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-4">
                    <div>
                        <div class="text-sm text-gray-600">Pageviews</div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            {{ number_format($data['pageviews'] ?? 0) }}
                        </div>
                    </div>
                    @if(isset($data['pageviews_change']))
                        <div class="flex items-center gap-1 text-sm {{ $data['pageviews_change'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                @if($data['pageviews_change'] >= 0)
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                                @else
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                @endif
                            </svg>
                            {{ abs($data['pageviews_change']) }}%
                        </div>
                    @endif
                </div>
            </div>
        @else
            <x-ui.empty-state
                title="No analytics data"
                description="Connect Google Analytics to track site traffic and engagement."
            />
        @endif
    </div>
</x-ui.card>
