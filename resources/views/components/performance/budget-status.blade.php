@props(['violations'])

@if(!empty($violations))
    <x-ui.card class="mb-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-900">Budget Status</h3>
        <div class="space-y-2">
            @foreach($violations as $v)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 {{ $v['exceeded'] ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50' }}">
                    <div class="flex min-w-0 items-center gap-2">
                        @if($v['exceeded'])
                            <svg class="h-4 w-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        @else
                            <svg class="h-4 w-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        @endif
                        <span class="truncate text-sm font-medium {{ $v['exceeded'] ? 'text-red-700' : 'text-green-700' }}">{{ $v['label'] }}</span>
                    </div>
                    <div class="flex flex-shrink-0 items-center gap-2">
                        <span class="text-sm {{ $v['exceeded'] ? 'text-red-600' : 'text-green-600' }}">
                            {{ is_numeric($v['actual']) && $v['actual'] > 10000 ? round($v['actual'] / 1024, 1) . ' KB' : $v['actual'] }}
                        </span>
                        <span class="text-xs text-gray-400">/</span>
                        <span class="text-xs text-gray-500">
                            {{ is_numeric($v['budget']) && $v['budget'] > 10000 ? round($v['budget'] / 1024, 1) . ' KB' : $v['budget'] }}
                        </span>
                        @if($v['exceeded'])
                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">OVER</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>
@endif
