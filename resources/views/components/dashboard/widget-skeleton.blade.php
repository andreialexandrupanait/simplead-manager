@props(['type' => 'default'])

<div class="animate-pulse">
    @if($type === 'stats')
        {{-- Stats Grid Skeleton --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @for($i = 0; $i < 6; $i++)
            <div class="space-y-2">
                <div class="h-4 w-16 rounded bg-gray-200"></div>
                <div class="h-8 w-12 rounded bg-gray-200"></div>
            </div>
            @endfor
        </div>

    @elseif($type === 'chart')
        {{-- Chart Skeleton --}}
        <div class="space-y-3">
            <div class="h-48 rounded bg-gray-200"></div>
            <div class="flex justify-between">
                <div class="h-4 w-20 rounded bg-gray-200"></div>
                <div class="h-4 w-20 rounded bg-gray-200"></div>
            </div>
        </div>

    @elseif($type === 'list')
        {{-- List Skeleton --}}
        <div class="space-y-3">
            @for($i = 0; $i < 5; $i++)
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex-1 space-y-2">
                    <div class="h-4 w-3/4 rounded bg-gray-200"></div>
                    <div class="h-3 w-1/2 rounded bg-gray-200"></div>
                </div>
                <div class="h-8 w-16 rounded bg-gray-200"></div>
            </div>
            @endfor
        </div>

    @else
        {{-- Default Skeleton --}}
        <div class="space-y-3">
            <div class="h-4 w-3/4 rounded bg-gray-200"></div>
            <div class="h-4 w-full rounded bg-gray-200"></div>
            <div class="h-4 w-5/6 rounded bg-gray-200"></div>
            <div class="mt-6 h-32 rounded bg-gray-200"></div>
        </div>
    @endif
</div>
