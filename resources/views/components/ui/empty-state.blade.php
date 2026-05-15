@props(['title', 'description' => null, 'icon' => 'inbox'])

<div class="flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-4 rounded-full bg-gray-100 dark:bg-gray-700 p-4">
        <x-dynamic-component :component="'icons.' . $icon" class="h-7 w-7 text-gray-400 dark:text-gray-300" aria-hidden="true" />
    </div>
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
    @if($description)
        <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
    @if(isset($action))
        <div class="mt-5">{{ $action }}</div>
    @endif
</div>
