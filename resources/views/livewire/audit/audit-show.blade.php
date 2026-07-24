<div class="space-y-6">
    <div>
        <a href="{{ route('audits.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">
            <x-icons.arrow-left class="h-4 w-4" aria-hidden="true" />
            {{ __('Înapoi la audituri') }}
        </a>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
                        {{ $audit->site?->name ?? $audit->prospect?->name ?? $audit->url }}
                    </h1>
                    <x-ui.badge :variant="$audit->status->badge()">{{ $audit->status->label() }}</x-ui.badge>
                </div>
                <a href="{{ $audit->url }}" target="_blank" rel="noopener" class="mt-1 inline-block text-sm text-gray-500 hover:underline dark:text-gray-400">
                    {{ $audit->url }}
                </a>
            </div>
            @unless (auth()->user()?->isViewer())
                <x-ui.button wire:click="startCrawl" variant="primary" wire:loading.attr="disabled" wire:target="startCrawl">
                    <span wire:loading.remove wire:target="startCrawl">
                        {{ $this->isRunning() ? __('Crawl în curs…') : __('Pornește crawl') }}
                    </span>
                    <span wire:loading wire:target="startCrawl">{{ __('Se pornește…') }}</span>
                </x-ui.button>
            @endunless
        </div>
    </div>

    @if (session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Crawl run progress --}}
    <div @if ($this->isRunning()) wire:poll.5s @endif>
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Colectare (crawl)') }}</h2>
                @if ($run)
                    <span class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        @if ($this->isRunning())
                            <x-ui.spinner class="h-4 w-4" />
                        @endif
                        {{ $run->status->value }}
                    </span>
                @endif
            </div>

            @if (! $run)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Niciun crawl încă. Pornește colectarea pentru a rula Screaming Frog + evaluatoarele.') }}
                </p>
            @else
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-400">{{ __('Exporturi prezente') }}</dt>
                        <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $run->manifest['present'] ?? '—' }}<span class="text-sm text-gray-400">/{{ $run->manifest['total'] ?? '—' }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-400">{{ __('CSV nepotrivite') }}</dt>
                        <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ $run->manifest['unmatched'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-400">{{ __('Durată') }}</dt>
                        <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $run->duration_ms ? round($run->duration_ms / 1000, 1).'s' : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-400">{{ __('Sursă') }}</dt>
                        <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ $run->source->value }}</dd>
                    </div>
                </dl>

                @if ($run->error)
                    <x-ui.alert type="error" class="mt-4">{{ $run->error }}</x-ui.alert>
                @endif

                @if (! empty($run->log))
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">{{ __('Jurnal crawl') }}</summary>
                        <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">@foreach ($run->log as $line){{ $line }}
@endforeach</pre>
                    </details>
                @endif
            @endif
        </x-ui.card>
    </div>

    {{-- Result summary --}}
    @if ($counts['total'] > 0)
        <x-ui.card>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Rezultate') }} ({{ $counts['total'] }})</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg bg-green-50 p-3 dark:bg-green-500/10">
                    <div class="text-xs uppercase tracking-wider text-green-700 dark:text-green-300">{{ __('EXISTĂ') }}</div>
                    <div class="text-xl font-semibold text-green-800 dark:text-green-200">{{ $counts['exista'] }}</div>
                </div>
                <div class="rounded-lg bg-red-50 p-3 dark:bg-red-500/10">
                    <div class="text-xs uppercase tracking-wider text-red-700 dark:text-red-300">{{ __('NU EXISTĂ') }}</div>
                    <div class="text-xl font-semibold text-red-800 dark:text-red-200">{{ $counts['nu_exista'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/40">
                    <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('NU SE APLICĂ') }}</div>
                    <div class="text-xl font-semibold text-gray-700 dark:text-gray-200">{{ $counts['nu_se_aplica'] }}</div>
                </div>
                <div class="rounded-lg bg-yellow-50 p-3 dark:bg-yellow-500/10">
                    <div class="text-xs uppercase tracking-wider text-yellow-700 dark:text-yellow-300">{{ __('Manual / de verificat') }}</div>
                    <div class="text-xl font-semibold text-yellow-800 dark:text-yellow-200">{{ $counts['manual'] }}</div>
                </div>
            </div>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Editorul de validare (stări + carduri) vine în valul următor.') }}
            </p>
        </x-ui.card>
    @endif
</div>
