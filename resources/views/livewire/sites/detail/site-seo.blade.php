<div>
    <div class="mb-6 flex justify-end">
        <x-ui.button wire:click="checkNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <span wire:loading.remove wire:target="checkNow">Check Now</span>
            <span wire:loading wire:target="checkNow">Checking...</span>
        </x-ui.button>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    @if($this->latestCheck)
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Score --}}
            <div class="lg:col-span-1">
                <x-ui.card>
                    <div class="text-center">
                        <div class="relative mx-auto h-36 w-36">
                            <svg class="h-36 w-36 -rotate-90" viewBox="0 0 120 120">
                                <circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                                <circle cx="60" cy="60" r="50" fill="none"
                                        stroke="{{ $this->latestCheck->score >= 80 ? '#10b981' : ($this->latestCheck->score >= 60 ? '#f59e0b' : '#ef4444') }}"
                                        stroke-width="10"
                                        stroke-dasharray="{{ ($this->latestCheck->score / 100) * 314.16 }} 314.16"
                                        stroke-linecap="round"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-bold {{ $this->latestCheck->score >= 80 ? 'text-green-600' : ($this->latestCheck->score >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                    {{ $this->latestCheck->score }}
                                </span>
                                <span class="text-xs text-gray-500">/ 100</span>
                            </div>
                        </div>
                        <p class="mt-2 text-sm font-medium text-gray-700">SEO Score</p>
                        <p class="text-xs text-gray-400">Checked {{ $this->latestCheck->checked_at->diffForHumans() }}</p>
                    </div>
                </x-ui.card>

                {{-- Score History --}}
                @if($this->history->count() > 1)
                    <x-ui.card class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">Score History</h3>
                        <div class="space-y-2">
                            @foreach($this->history as $check)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500">{{ $check->checked_at->format('M d, Y') }}</span>
                                    <span class="font-medium {{ $check->score >= 80 ? 'text-green-600' : ($check->score >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $check->score }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </div>

            <div class="lg:col-span-2 space-y-6">
                {{-- SEO Checklist --}}
                <x-ui.card :padding="false">
                    <div class="border-b p-4">
                        <h3 class="text-lg font-semibold text-gray-900">SEO Checklist</h3>
                    </div>
                    <div class="divide-y">
                        {{-- Title --}}
                        <div class="flex items-start gap-3 px-4 py-3">
                            @if(!empty($this->latestCheck->homepage_title))
                                <svg class="h-5 w-5 shrink-0 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="h-5 w-5 shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">Title Tag</p>
                                @if($this->latestCheck->homepage_title)
                                    <p class="text-xs text-gray-500 truncate">{{ $this->latestCheck->homepage_title }}</p>
                                    <p class="text-xs text-gray-400">{{ strlen($this->latestCheck->homepage_title) }} characters</p>
                                @else
                                    <p class="text-xs text-red-500">Missing</p>
                                @endif
                            </div>
                        </div>

                        {{-- Meta Description --}}
                        <div class="flex items-start gap-3 px-4 py-3">
                            @if(!empty($this->latestCheck->homepage_meta_description))
                                <svg class="h-5 w-5 shrink-0 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="h-5 w-5 shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">Meta Description</p>
                                @if($this->latestCheck->homepage_meta_description)
                                    <p class="text-xs text-gray-500 line-clamp-2">{{ $this->latestCheck->homepage_meta_description }}</p>
                                    <p class="text-xs text-gray-400">{{ strlen($this->latestCheck->homepage_meta_description) }} characters</p>
                                @else
                                    <p class="text-xs text-red-500">Missing</p>
                                @endif
                            </div>
                        </div>

                        {{-- Sitemap --}}
                        <div class="flex items-start gap-3 px-4 py-3">
                            @if($this->latestCheck->has_sitemap)
                                <svg class="h-5 w-5 shrink-0 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="h-5 w-5 shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-gray-900">XML Sitemap</p>
                                @if($this->latestCheck->has_sitemap)
                                    <p class="text-xs text-gray-500">{{ $this->latestCheck->sitemap_url }}</p>
                                    @if($this->latestCheck->sitemap_pages_count)
                                        <p class="text-xs text-gray-400">{{ number_format($this->latestCheck->sitemap_pages_count) }} pages</p>
                                    @endif
                                @else
                                    <p class="text-xs text-red-500">Not found</p>
                                @endif
                            </div>
                        </div>

                        {{-- Robots.txt --}}
                        <div class="flex items-start gap-3 px-4 py-3">
                            @if($this->latestCheck->has_robots_txt)
                                <svg class="h-5 w-5 shrink-0 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @else
                                <svg class="h-5 w-5 shrink-0 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-gray-900">Robots.txt</p>
                                @if(!empty($this->latestCheck->robots_txt_issues))
                                    <p class="text-xs text-yellow-600">{{ count($this->latestCheck->robots_txt_issues) }} issue(s) found</p>
                                @elseif($this->latestCheck->has_robots_txt)
                                    <p class="text-xs text-green-500">Properly configured</p>
                                @else
                                    <p class="text-xs text-red-500">Not found</p>
                                @endif
                            </div>
                        </div>

                        @foreach([
                            'has_og_tags' => 'Open Graph Tags',
                            'has_twitter_cards' => 'Twitter Cards',
                            'has_schema_markup' => 'Schema Markup',
                            'has_canonical' => 'Canonical URLs',
                            'has_h1' => 'H1 Tag',
                            'heading_hierarchy_ok' => 'Heading Hierarchy',
                        ] as $key => $label)
                            <div class="flex items-center gap-3 px-4 py-3">
                                @if($this->latestCheck->$key)
                                    <svg class="h-5 w-5 shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                @else
                                    <svg class="h-5 w-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                @endif
                                <p class="text-sm font-medium text-gray-900">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                {{-- Google Preview --}}
                @if($this->latestCheck->homepage_title || $this->latestCheck->homepage_meta_description)
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">Google Preview</h3>
                        <div class="rounded-lg border bg-white p-4">
                            <p class="text-sm text-green-700 truncate">{{ $this->latestCheck->site->url }}</p>
                            <p class="text-lg text-blue-700 hover:underline truncate">{{ $this->latestCheck->homepage_title ?: 'No title set' }}</p>
                            <p class="text-sm text-gray-600 line-clamp-2">{{ $this->latestCheck->homepage_meta_description ?: 'No meta description set.' }}</p>
                        </div>
                    </x-ui.card>
                @endif

                {{-- Recommendations --}}
                @if(count($this->recommendations) > 0)
                    <x-ui.card :padding="false">
                        <div class="border-b p-4">
                            <h3 class="text-lg font-semibold text-gray-900">Recommendations</h3>
                        </div>
                        <div class="divide-y">
                            @foreach($this->recommendations as $rec)
                                <div class="px-4 py-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <x-ui.badge :variant="match($rec['priority']) { 'high' => 'red', 'medium' => 'yellow', 'low' => 'gray', default => 'gray' }">
                                            {{ ucfirst($rec['priority']) }}
                                        </x-ui.badge>
                                        <span class="text-sm font-medium text-gray-900">{{ $rec['title'] }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500">{{ $rec['description'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </div>
        </div>
    @else
        <x-ui.card>
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-gray-100 p-3">
                    <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">No SEO Data Yet</p>
                <p class="mt-1 text-xs text-gray-500">Click "Check Now" to run an SEO analysis of your site.</p>
            </div>
        </x-ui.card>
    @endif
</div>
