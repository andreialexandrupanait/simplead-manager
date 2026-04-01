@php
if (! isset($scrollTo)) {
    $scrollTo = false;
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        {{-- Spacer to prevent content from hiding behind the fixed pagination bar --}}
        <div class="h-14"></div>

        <div x-data="{
                left: 0,
                width: 0,
                update() {
                    const main = document.getElementById('main-content');
                    if (main) {
                        const rect = main.getBoundingClientRect();
                        this.left = rect.left;
                        this.width = rect.width;
                    }
                }
             }"
             x-init="
                update();
                window.addEventListener('resize', () => update());
                if (document.querySelector('[data-main]')) {
                    new ResizeObserver(() => update()).observe(document.querySelector('[data-main]'));
                }
             "
             :style="`left: ${left}px; width: ${width}px`"
             class="fixed bottom-0 z-10 px-6 lg:px-8 pt-3 pb-2 bg-gradient-to-t from-white via-white to-white/80 backdrop-blur-sm border-t border-gray-200/50">
            <div class="mx-auto max-w-7xl">
                <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between">
                    {{-- Mobile: simple previous/next --}}
                    <div class="flex justify-between flex-1 sm:hidden">
                        <span>
                            @if ($paginator->onFirstPage())
                                <span class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 bg-white border border-gray-200 cursor-not-allowed rounded-lg">
                                    Previous
                                </span>
                            @else
                                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 transition">
                                    Previous
                                </button>
                            @endif
                        </span>

                        <span>
                            @if ($paginator->hasMorePages())
                                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 transition">
                                    Next
                                </button>
                            @else
                                <span class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 bg-white border border-gray-200 cursor-not-allowed rounded-lg">
                                    Next
                                </span>
                            @endif
                        </span>
                    </div>

                    {{-- Desktop: full pagination --}}
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-600">
                                Showing
                                <span class="font-medium">{{ $paginator->firstItem() }}</span>
                                to
                                <span class="font-medium">{{ $paginator->lastItem() }}</span>
                                of
                                <span class="font-medium">{{ $paginator->total() }}</span>
                                results
                            </p>
                        </div>

                        <div>
                            <span class="relative z-0 inline-flex items-center gap-1">
                                {{-- Previous --}}
                                @if ($paginator->onFirstPage())
                                    <span aria-disabled="true" class="relative inline-flex items-center px-2.5 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-200 cursor-not-allowed rounded-lg">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                    </span>
                                @else
                                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-2.5 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 transition" aria-label="Previous">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                    </button>
                                @endif

                                {{-- Page Numbers --}}
                                @foreach ($elements as $element)
                                    @if (is_string($element))
                                        <span aria-disabled="true" class="relative inline-flex items-center px-3.5 py-2 text-sm font-medium text-gray-400 bg-white border border-gray-200 rounded-lg cursor-default">{{ $element }}</span>
                                    @endif

                                    @if (is_array($element))
                                        @foreach ($element as $page => $url)
                                            @if ($page == $paginator->currentPage())
                                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}" aria-current="page" class="relative inline-flex items-center px-3.5 py-2 text-sm font-semibold text-white bg-purple-600 border border-purple-600 rounded-lg cursor-default">{{ $page }}</span>
                                            @else
                                                <button type="button" wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-3.5 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 transition" aria-label="Go to page {{ $page }}">{{ $page }}</button>
                                            @endif
                                        @endforeach
                                    @endif
                                @endforeach

                                {{-- Next --}}
                                @if ($paginator->hasMorePages())
                                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="relative inline-flex items-center px-2.5 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 transition" aria-label="Next">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                    </button>
                                @else
                                    <span aria-disabled="true" class="relative inline-flex items-center px-2.5 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-200 cursor-not-allowed rounded-lg">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                    </span>
                                @endif
                            </span>
                        </div>
                    </div>
                </nav>
            </div>
        </div>
    @endif
</div>
