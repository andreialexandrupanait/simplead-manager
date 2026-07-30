@props([
    'sections' => [],
])

@php
    $tabs = [
        ['route' => 'settings.account', 'label' => __('Account')],
    ];

    if (auth()->user()->isAdmin()) {
        $tabs = array_merge([
            ['route' => 'settings.general', 'label' => __('General')],
            ['route' => 'settings.notifications', 'label' => __('Notifications')],
            ['route' => 'settings.integrations', 'label' => __('Integrations')],
            ['route' => 'settings.data', 'label' => __('Backup & Data')],
            ['route' => 'settings.report-templates', 'label' => __('Reports')],
        ], $tabs);
    }
@endphp

{{-- Settings navigation: the six tabs, and — for the tab you are on — its
     sections.

     The previous bar got four things wrong at once. It used bg-gray-50
     (#f0f1f3) over a #FAFAFA page, so it read as a darker band in light mode
     and, because .dark .bg-gray-50 resolves to the body colour, vanished in
     dark. It sat at top-16 (64px) under a header that is h-16 *plus* a 1px
     border, leaving a hairline of scrolling content showing through. Its
     -mx-6/-mx-8 tried to bleed to the edges from inside `mx-auto max-w-7xl`,
     which only works until the container caps. And it carried scrollbar-hide,
     a utility this project never defined.

     So: the real page colour, top-[65px], and no negative margins — the bar
     lives in the content column and behaves the same at every width. --}}
<div class="sticky top-[65px] z-10 mb-6 bg-[#FAFAFA] dark:bg-gray-900">
    <nav class="flex gap-4 overflow-x-auto border-b border-gray-200" aria-label="{{ __('Settings') }}">
        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               @if(request()->routeIs($tab['route'])) aria-current="page" @endif
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['route'])
                          ? 'border-accent-500 text-accent-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    @if($sections !== [])
        {{-- Jump links for this tab. A settings tab is a long page; without
             these you scroll hunting for the thing you came to change. --}}
        <div class="flex flex-wrap items-center gap-x-1 gap-y-1 py-2">
            @foreach($sections as $anchor => $label)
                <a href="#{{ $anchor }}"
                   class="rounded-md px-2 py-1 text-[13px] text-gray-500 transition hover:bg-gray-100 hover:text-gray-900">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    @endif
</div>
