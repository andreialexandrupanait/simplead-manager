@props([
    'id' => null,
    'title' => null,
    'subtitle' => null,
])

{{-- One section of a settings tab.

     Every settings section used to open with its own 40×40 rounded icon chip,
     each in a different colour — accent, green, amber, blue. Four sections
     stacked read as four unrelated pastilles carrying no information. The title
     carries the hierarchy now.

     scroll-mt-36 keeps the anchor target clear of the app header (65px) plus
     the sticky settings nav above it. --}}
<section @if($id) id="{{ $id }}" @endif class="scroll-mt-36">
    {{-- Attributes pass through to the card so a section can carry its own
         emphasis — the Danger Zone needs a red border, not a red inner box. --}}
    <x-ui.card {{ $attributes }}>
        @if($title)
            <div class="mb-4 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
                    @if($subtitle)
                        <p class="mt-0.5 text-sm text-gray-500">{{ $subtitle }}</p>
                    @endif
                </div>
                @if(isset($actions) && ! $actions->isEmpty())
                    <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
                @endif
            </div>
        @endif

        {{ $slot }}
    </x-ui.card>
</section>
