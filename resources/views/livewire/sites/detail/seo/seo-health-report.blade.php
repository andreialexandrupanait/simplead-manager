<div>
    <x-ui.page-header title="{{ __('SEO Agent') }}" subtitle="{{ __('Expert analysis with prioritized recommendations') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    @if(!$report)
        {{-- Run Analysis --}}
        <div class="rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900/30">
                <svg class="h-8 w-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('SEO Agent') }}</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Analizeaza complet site-ul si genereaza recomandari prioritizate bazate pe audit, keywords, backlinks, Core Web Vitals si continut.') }}</p>
            <div class="mt-6">
                <x-ui.button variant="primary" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze">
                    <span wire:loading.remove wire:target="analyze">{{ __('Ruleaza Analiza') }}</span>
                    <span wire:loading wire:target="analyze">{{ __('Se analizeaza...') }}</span>
                </x-ui.button>
            </div>
        </div>
    @else
        @php
            $hs = $report['health_score'];
            $hsColor = $hs >= 80 ? 'green' : ($hs >= 50 ? 'yellow' : 'red');
            $hsLabel = $hs >= 80 ? 'Bun' : ($hs >= 50 ? 'Necesita Atentie' : 'Critic');
        @endphp

        {{-- Health Score --}}
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-6">
                    <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-full ring-4
                        {{ $hsColor === 'green' ? 'bg-green-50 ring-green-400 dark:bg-green-900/20 dark:ring-green-600' : ($hsColor === 'yellow' ? 'bg-yellow-50 ring-yellow-400 dark:bg-yellow-900/20 dark:ring-yellow-600' : 'bg-red-50 ring-red-400 dark:bg-red-900/20 dark:ring-red-600') }}">
                        <span class="text-3xl font-bold {{ $hsColor === 'green' ? 'text-green-600 dark:text-green-400' : ($hsColor === 'yellow' ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">{{ $hs }}</span>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">SEO Health: {{ $hsLabel }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $report['summary'] }}</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Generat') }}: {{ $report['generated_at']->format('d M Y, H:i') }}</p>
                    </div>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="analyze" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="analyze">{{ __('Re-analizeaza') }}</span>
                    <span wire:loading wire:target="analyze">{{ __('Se analizeaza...') }}</span>
                </x-ui.button>
            </div>
        </div>

        {{-- Section Scores --}}
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @php
                $sections = [
                    ['label' => 'Audit', 'value' => $report['audit']['score'] ?? '—', 'suffix' => '/100', 'icon' => 'target'],
                    ['label' => 'Keywords', 'value' => $report['sections']['keywords']['in_top_10'], 'suffix' => ' in Top 10', 'icon' => 'search'],
                    ['label' => 'Performance', 'value' => $report['sections']['cwv']['performance_score'] ?? '—', 'suffix' => '/100', 'icon' => 'zap'],
                    ['label' => 'Backlinks', 'value' => $report['sections']['backlinks']['total'], 'suffix' => ' total', 'icon' => 'link'],
                    ['label' => 'Toxic', 'value' => $report['sections']['backlinks']['toxic'], 'suffix' => ' toxic', 'icon' => 'alert-triangle'],
                ];
            @endphp
            @foreach($sections as $s)
                <div class="rounded-xl border border-gray-200 bg-white p-4 text-center dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $s['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $s['value'] }}<span class="text-sm font-normal text-gray-400">{{ $s['suffix'] }}</span></p>
                </div>
            @endforeach
        </div>

        {{-- Action Items --}}
        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Recomandari Prioritizate') }} ({{ count($report['actions']) }})</h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Ordonate dupa impact — rezolva de sus in jos') }}</p>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($report['actions'] as $i => $action)
                    @php
                        $sevColor = match($action['severity']) {
                            'critical' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                            'high' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                            'medium' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                            default => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                        };
                        $catIcon = match($action['category']) {
                            'technical' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
                            'keywords' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
                            'performance' => 'M13 10V3L4 14h7v7l9-11h-7z',
                            'backlinks' => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
                            'content' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            default => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                        };
                    @endphp
                    <div class="flex items-start gap-4 px-6 py-4">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                            {{ $i + 1 }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $sevColor }}">{{ $action['severity'] }}</span>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $action['title'] }}</h3>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $action['description'] }}</p>
                            @if(!empty($action['tab']))
                                <p class="mt-1 text-xs text-purple-600 dark:text-purple-400">→ {{ $action['tab'] }}</p>
                            @endif
                        </div>
                        <div class="shrink-0">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full {{ $action['impact'] >= 80 ? 'bg-red-50 dark:bg-red-900/20' : ($action['impact'] >= 60 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-blue-50 dark:bg-blue-900/20') }}">
                                <span class="text-xs font-bold {{ $action['impact'] >= 80 ? 'text-red-600 dark:text-red-400' : ($action['impact'] >= 60 ? 'text-yellow-600 dark:text-yellow-400' : 'text-blue-600 dark:text-blue-400') }}">{{ $action['impact'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(empty($report['actions']))
                <div class="px-6 py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('Felicitari! Nicio problema critica detectata.') }}</h3>
                </div>
            @endif
        </div>
    @endif
</div>
