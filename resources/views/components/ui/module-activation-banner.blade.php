@props(['title', 'description', 'icon' => 'settings'])

<div class="mb-6 rounded-lg border border-accent-200 bg-accent-50 p-4">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent-100">
                <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5 text-accent-600" />
            </div>
            <div>
                <p class="text-sm font-medium text-accent-900">{{ $title }}</p>
                <p class="text-xs text-accent-700">{{ $description }}</p>
            </div>
        </div>
        <div class="shrink-0">
            {{ $slot }}
        </div>
    </div>
</div>
