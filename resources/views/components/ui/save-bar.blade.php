@props([
    'target' => 'save',
    'message' => null,
    'label' => null,
])

{{-- The dirty-state save bar, sticky to the bottom of the card it closes.

     This markup was byte-identical in seven places (every tweaks-* and
     security-* screen). It is a component now so settings can use the same one
     instead of growing an eighth copy. --}}
<div class="sticky bottom-0 -mx-6 -mb-6 mt-6 flex items-center justify-between rounded-b-xl border-t border-gray-200 bg-white px-6 py-4 shadow-lg">
    <p class="text-sm text-gray-500">{{ $message ?? __('You have unsaved changes') }}</p>

    <x-ui.button wire:click="{{ $target }}" wire:loading.attr="disabled" wire:target="{{ $target }}">
        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $target }}" />
        {{ $label ?? __('Save changes') }}
    </x-ui.button>
</div>
