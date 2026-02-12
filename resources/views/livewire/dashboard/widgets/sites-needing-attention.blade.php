<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="list"
    wire:init="loadWidget"
>
    <x-slot name="actions">
        @if($isLoaded && $this->data && $this->data['count'] > 0)
            <span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">
                {{ $this->data['count'] }}
            </span>
        @endif
    </x-slot>

    @if($isLoaded && $this->data)
        @if($this->data['count'] === 0)
            <div class="flex h-32 items-center justify-center">
                <div class="text-center">
                    <svg class="mx-auto h-10 w-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <p class="mt-2 text-sm font-medium text-gray-900">All Sites Healthy!</p>
                    <p class="text-xs text-gray-500">No sites need attention</p>
                </div>
            </div>
        @else
            <div class="space-y-2">
                @foreach($this->data['sites'] as $site)
                    <a
                        href="{{ route('sites.overview', $site) }}"
                        class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 transition hover:border-purple-300 hover:bg-purple-50"
                    >
                        {{-- Site Icon/Favicon --}}
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $site->is_up ? 'bg-gray-100' : 'bg-red-100' }}">
                            @if($site->favicon)
                                <img src="{{ $site->favicon }}" alt="{{ $site->name }}" class="h-6 w-6 rounded">
                            @else
                                <svg class="h-5 w-5 {{ $site->is_up ? 'text-gray-400' : 'text-red-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                </svg>
                            @endif
                        </div>

                        {{-- Site Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="truncate text-sm font-medium text-gray-900">{{ $site->name }}</h4>

                                {{-- Issue Badge --}}
                                @if(!$site->is_up)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                        </svg>
                                        Down
                                    </span>
                                @elseif($site->health_score < 70)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                        Low Health
                                    </span>
                                @elseif($site->sslCertificate && $site->sslCertificate->expires_at && $site->sslCertificate->expires_at->lte(now()->addDays(14)))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">
                                        SSL Expiring
                                    </span>
                                @elseif($site->core_update_version)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                                        Update Available
                                    </span>
                                @endif
                            </div>

                            {{-- Health Score Bar --}}
                            @if($site->health_score !== null)
                                <div class="mt-2 flex items-center gap-2">
                                    <div class="flex-1 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                        <div
                                            class="h-full transition-all {{ $site->health_score >= 90 ? 'bg-green-500' : ($site->health_score >= 70 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                            style="width: {{ $site->health_score }}%"
                                        ></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-600">{{ $site->health_score }}%</span>
                                </div>
                            @endif
                        </div>

                        {{-- Arrow --}}
                        <svg class="h-5 w-5 shrink-0 text-gray-400 transition group-hover:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</x-dashboard.widget-container>
