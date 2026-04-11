<div>
    <x-ui.page-header title="{{ __('Technical SEO') }}" subtitle="{{ __('Technical factors affecting your site\'s search visibility') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    @if($this->latestAudit)
        <div class="space-y-6">

            {{-- Search Engine Visibility --}}
            @php $visibility = $this->searchVisibility; @endphp
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100">
                            <svg class="h-5 w-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('Search Engine Visibility') }}</h3>
                            <p class="text-xs text-gray-500">{{ __('Whether search engines can index this site') }}</p>
                        </div>
                    </div>
                    @if($visibility['visible'] ?? true)
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                            {{ __('Visible') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            {{ __('Blocked') }}
                        </span>
                    @endif
                </div>
            </x-ui.card>

            {{-- Robots.txt --}}
            @php $robots = $this->robotsTxt; @endphp
            <x-ui.card>
                <div class="mb-3 flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                        <svg class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">robots.txt</h3>
                        <p class="text-xs text-gray-500">{{ __('Crawler access rules') }}</p>
                    </div>
                </div>

                @if(!empty($robots['content']))
                    <pre class="mb-3 max-h-40 overflow-y-auto rounded-lg bg-gray-900 p-3 text-xs text-green-400">{{ $robots['content'] }}</pre>
                @else
                    <p class="mb-3 text-sm text-gray-500">{{ __('No robots.txt file found or content is empty.') }}</p>
                @endif

                @if(!empty($robots['issues']))
                    <div class="space-y-2">
                        @foreach($robots['issues'] as $issue)
                            <div class="flex items-start gap-2 rounded-lg bg-yellow-50 px-3 py-2">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                </svg>
                                <p class="text-xs text-yellow-700">{{ $issue }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- Sitemaps --}}
            @php $sitemapData = $this->sitemaps; @endphp
            <x-ui.card>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-100">
                        <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Sitemaps') }}</h3>
                </div>

                @php $maps = $sitemapData['maps'] ?? []; @endphp

                @if(!empty($maps))
                    <div class="space-y-2">
                        @foreach($maps as $sitemap)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2.5 dark:border-gray-700">
                                <div class="min-w-0 flex-1 overflow-hidden">
                                    <a href="{{ $sitemap['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="block truncate text-xs font-medium text-purple-600 hover:text-purple-700 dark:text-purple-400">
                                        {{ $sitemap['url'] }}
                                    </a>
                                </div>
                                @if(!empty($sitemap['url_count']))
                                    <span class="ml-3 shrink-0 text-xs text-gray-500">
                                        {{ number_format($sitemap['url_count']) }} {{ __('URLs') }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-gray-200 py-4 text-center">
                        <p class="text-sm text-gray-500">{{ __('No sitemaps found') }}</p>
                    </div>
                @endif
            </x-ui.card>

            {{-- Structured Data --}}
            <x-ui.card>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-purple-100">
                        <svg class="h-5 w-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Structured Data') }}</h3>
                </div>

                @php
                    $sd = $this->structuredData;
                    $allTypes = collect($sd)->pluck('types')->flatten()->unique()->values();
                    $invalidCount = collect($sd)->where('valid', false)->count();
                @endphp

                @if($allTypes->isNotEmpty())
                    <div class="mb-3 flex flex-wrap gap-2">
                        @foreach($allTypes as $type)
                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-700">
                                {{ $type }}
                            </span>
                        @endforeach
                    </div>
                @else
                    <p class="mb-3 text-sm text-gray-500">{{ __('No structured data found.') }}</p>
                @endif

                @if($invalidCount > 0)
                    <div class="flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        <p class="text-xs text-red-700">{{ $invalidCount }} {{ __('invalid JSON-LD block(s) detected') }}</p>
                    </div>
                @endif
            </x-ui.card>

            {{-- Redirect Chains --}}
            @php $redirectData = $this->redirects; @endphp
            <x-ui.card>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-orange-100">
                        <svg class="h-5 w-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 9l3 3m0 0l-3 3m3-3H8m13 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Redirect Chains') }}</h3>
                </div>

                @if(!empty($redirectData['chain']) && count($redirectData['chain']) > 1)
                    <div class="mb-3 flex flex-wrap items-center gap-1">
                        @foreach($redirectData['chain'] as $step)
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                {{ $step['status'] ?? '' }} {{ Str::limit($step['url'] ?? '', 40) }}
                            </span>
                            @if(!$loop->last)
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            @endif
                        @endforeach
                    </div>
                    @if(!empty($redirectData['issues']))
                        @foreach($redirectData['issues'] as $issue)
                            <div class="mt-2 flex items-start gap-2 rounded-lg bg-yellow-50 px-3 py-2">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                </svg>
                                <p class="text-xs text-yellow-700">{{ $issue }}</p>
                            </div>
                        @endforeach
                    @endif
                @else
                    <div class="rounded-lg bg-green-50 px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                            <p class="text-sm text-green-700">{{ __('No redirect chain issues.') }}</p>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Broken Links --}}
            @php $broken = $this->brokenLinks; @endphp
            <x-ui.card>
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100">
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('Broken Links') }}</h3>
                    </div>
                    @if(($broken['broken_count'] ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700">
                            {{ $broken['broken_count'] }} {{ __('found') }}
                        </span>
                    @endif
                </div>

                @if(!empty($broken['broken']))
                    <div class="divide-y divide-gray-100">
                        @foreach($broken['broken'] as $link)
                            <div class="flex items-center justify-between py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium text-gray-700">{{ $link['url'] ?? '' }}</p>
                                    @if(!empty($link['error']))
                                        <p class="mt-0.5 text-xs text-gray-400">{{ $link['error'] }}</p>
                                    @endif
                                </div>
                                @if(!empty($link['status']))
                                    <span class="ml-3 shrink-0 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">
                                        {{ $link['status'] }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-lg bg-green-50 px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                            <p class="text-sm text-green-700">{{ __('No broken links detected.') }}</p>
                        </div>
                    </div>
                @endif
            </x-ui.card>

        </div>
    @else
        {{-- No audit data --}}
        <x-ui.card>
            <div class="py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('No technical data available') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Run an SEO audit to analyze robots.txt, sitemaps, structured data, redirects, and broken links.') }}</p>
                <div class="mt-4">
                    <x-ui.button variant="primary" size="sm" wire:click="runAudit" wire:loading.attr="disabled" wire:target="runAudit">
                        <span wire:loading.remove wire:target="runAudit">{{ __('Run SEO Audit') }}</span>
                        <span wire:loading wire:target="runAudit">{{ __('Starting audit...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif
</div>
