@props(['items' => []])

{{-- An integration is a row, not a 200px card.

     This replaced a 4-column grid of cards where every unconfigured service
     wore a red "Not configured" badge — a wall of red on a fresh install, for
     nothing being wrong. Red is for broken; grey is for "you haven't set this
     up yet". Configured rows carried `ring-2 ring-blue-500`, a raw blue the
     rest of the app never uses. --}}
<div class="divide-y divide-gray-100">
    @foreach($items as $item)
        <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                <x-settings.integration-icon :name="$item['name']" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900">{{ $item['label'] }}</p>
                <p class="truncate text-xs text-gray-500">
                    {{ $item['on'] ? $item['detail'] : $item['about'] }}
                </p>
            </div>

            <x-ui.badge :variant="$item['on'] ? 'green' : 'gray'">
                {{ $item['on'] ? __('Connected') : __('Not set up') }}
            </x-ui.badge>

            <x-ui.button variant="secondary" size="sm"
                         x-on:click="$dispatch('open-modal-{{ $item['modal'] }}')">
                {{ $item['on'] ? __('Manage') : __('Connect') }}
            </x-ui.button>
        </div>
    @endforeach
</div>
