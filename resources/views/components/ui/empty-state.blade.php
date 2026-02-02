@props(['title', 'description' => null, 'icon' => 'inbox'])

<div class="flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-4 rounded-full bg-gray-100 p-4">
        <x-dynamic-component :component="'icons.' . $icon" class="h-8 w-8 text-gray-400" />
    </div>
    <h3 class="text-sm font-medium text-gray-900">{{ $title }}</h3>
    @if($description)
        <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
    @endif
    @if(isset($action))
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
