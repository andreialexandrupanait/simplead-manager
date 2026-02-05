@props(['frames', 'fcp' => null, 'lcp' => null])

<x-ui.card class="mb-6 overflow-hidden">
    <h3 class="mb-4 text-lg font-semibold text-gray-900">Loading Filmstrip</h3>
    <div class="-mx-6 overflow-x-auto px-6 pb-2">
        <div class="flex gap-2" style="min-width: max-content;">
            @foreach($frames as $frame)
                @php
                    $timing = $frame['timing'] ?? 0;
                    $timingMs = round($timing);
                    $isFcp = $fcp !== null && abs($timing - ($fcp * 1000)) < 200;
                    $isLcp = $lcp !== null && abs($timing - ($lcp * 1000)) < 200;
                @endphp
                <div class="flex flex-col items-center">
                    <div class="relative h-[90px] w-[120px] overflow-hidden rounded border border-gray-200 bg-gray-100">
                        @if(!empty($frame['data']))
                            <img src="{{ $frame['data'] }}" alt="Frame at {{ $timingMs }}ms" class="h-full w-full object-cover" loading="lazy">
                        @endif
                        @if($isFcp)
                            <div class="absolute bottom-0 left-0 right-0 bg-green-500/80 px-1 py-0.5 text-center text-[9px] font-bold text-white">FCP</div>
                        @endif
                        @if($isLcp)
                            <div class="absolute bottom-0 left-0 right-0 bg-orange-500/80 px-1 py-0.5 text-center text-[9px] font-bold text-white">LCP</div>
                        @endif
                    </div>
                    <span class="mt-1 text-xs text-gray-500">
                        @if($timingMs >= 1000)
                            {{ round($timingMs / 1000, 1) }}s
                        @else
                            {{ $timingMs }}ms
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
</x-ui.card>
