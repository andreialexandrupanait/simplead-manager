@props(['title', 'widgetId', 'loading' => false, 'skeletonType' => 'default'])

<div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-900/5">
    {{-- Widget Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
        <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>

        {{-- Settings Menu --}}
        <div x-data="dropdown()" @click.outside="close($event)">
            <button @click="toggle()" class="rounded-lg p-1 text-gray-400 hover:bg-gray-50 hover:text-gray-600">
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                </svg>
            </button>
            <div x-show="open" x-ref="panelEl" class="fixed z-50 mt-2 w-48 rounded-lg bg-white shadow-lg ring-1 ring-gray-900/5">
                <button wire:click="$dispatch('refresh-widget-{{ $widgetId }}')" class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50">
                    Refresh
                </button>
            </div>
        </div>
    </div>

    {{-- Widget Content --}}
    <div class="p-6">
        @if($loading)
            <x-dashboard.widget-skeleton :type="$skeletonType" />
        @else
            {{ $slot }}
        @endif
    </div>
</div>
