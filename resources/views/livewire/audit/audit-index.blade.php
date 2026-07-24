<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Audits') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Modulul unificat SEO/audit — 82 de verificări per audit.') }}
            </p>
        </div>
        <x-ui.button :href="route('audits.create')" variant="primary" wire:navigate>
            {{ __('+ Audit nou') }}
        </x-ui.button>
    </div>

    <div class="w-full max-w-xs">
        <label for="status-filter" class="sr-only">{{ __('Filtrează după stare') }}</label>
        <x-ui.select id="status-filter" wire:model.live="status">
            <option value="">{{ __('Toate stările') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($audits->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                icon="target"
                :title="__('Niciun audit')"
                :description="__('Creează primul audit pentru un site conectat sau un prospect.')"
            >
                <x-slot:action>
                    <x-ui.button :href="route('audits.create')" variant="primary" wire:navigate>
                        {{ __('+ Audit nou') }}
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Țintă') }}</x-ui.th>
                <x-ui.th>{{ __('URL') }}</x-ui.th>
                <x-ui.th>{{ __('Stare') }}</x-ui.th>
                <x-ui.th>{{ __('Ultimul crawl') }}</x-ui.th>
                <x-ui.th>{{ __('Creat') }}</x-ui.th>
                <x-ui.th><span class="sr-only">{{ __('Acțiuni') }}</span></x-ui.th>
            </x-slot:head>

            @foreach ($audits as $audit)
                <tr wire:key="audit-{{ $audit->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <x-ui.td class="font-medium text-gray-900 dark:text-white">
                        {{ $audit->site?->name ?? $audit->prospect?->name ?? '—' }}
                        <span class="ml-1 text-xs text-gray-400">
                            {{ $audit->site_id ? __('site') : __('prospect') }}
                        </span>
                    </x-ui.td>
                    <x-ui.td class="max-w-xs truncate text-gray-500 dark:text-gray-400">{{ $audit->url }}</x-ui.td>
                    <x-ui.td>
                        <x-ui.badge :variant="$audit->status->badge()">{{ $audit->status->label() }}</x-ui.badge>
                    </x-ui.td>
                    <x-ui.td class="text-gray-500 dark:text-gray-400">
                        {{ $audit->latestRun?->status?->value ?? '—' }}
                    </x-ui.td>
                    <x-ui.td class="text-gray-500 dark:text-gray-400">{{ $audit->created_at?->diffForHumans() }}</x-ui.td>
                    <x-ui.td class="text-right">
                        <x-ui.button :href="route('audits.show', $audit)" variant="ghost" size="sm" wire:navigate>
                            {{ __('Deschide') }}
                        </x-ui.button>
                    </x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>

        <div>{{ $audits->links() }}</div>
    @endif
</div>
