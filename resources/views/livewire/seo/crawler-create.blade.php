<div>
    <x-ui.page-header title="{{ __('New Crawl') }}" subtitle="{{ __('Enter any URL to start crawling') }}" />

    <div class="mx-auto max-w-2xl">
        <x-ui.card>
            {{-- URL Input --}}
            <div class="mb-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('URL to crawl') }} *</label>
                <input
                    type="url"
                    wire:model.live.debounce.500ms="urlInput"
                    placeholder="https://example.com"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"
                />
                @error('urlInput') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

                @if($this->detectedSite)
                    <p class="mt-1.5 flex items-center gap-1.5 text-sm text-green-600">
                        <x-dynamic-component component="icons.check-circle" class="h-4 w-4" />
                        {{ __('Known site:') }} <strong>{{ $this->detectedSite->name }}</strong> — {{ __('results will be linked to this site') }}
                    </p>
                @elseif($urlInput && strlen($urlInput) > 10)
                    <p class="mt-1.5 text-sm text-gray-500">{{ __('Standalone crawl — not linked to any site.') }}</p>
                @endif
            </div>

            {{-- Optional site override --}}
            <div class="mb-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Link to site') }} <span class="text-gray-400">({{ __('optional') }})</span></label>
                <select wire:model="siteId" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="">{{ __('None — standalone crawl') }}</option>
                    @foreach($this->sites as $site)
                        <option value="{{ $site->id }}">{{ $site->name }} — {{ $site->url }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Config --}}
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Max Pages') }}</label>
                    <input type="number" wire:model="maxPages" min="10" max="2000" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Rate Limit (ms)') }}</label>
                    <input type="number" wire:model="rateLimit" min="100" max="5000" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Max Depth') }}</label>
                    <input type="number" wire:model="maxDepth" min="1" max="50" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" />
                </div>
            </div>

            <div class="mb-6">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="respectRobots" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                    {{ __('Respect robots.txt') }}
                </label>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('seo.crawler.index') }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">{{ __('Cancel') }}</a>
                <x-ui.button variant="primary" wire:click="startCrawl" wire:loading.attr="disabled" wire:target="startCrawl">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="startCrawl" />
                    {{ __('Start Crawl') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
