@props([
    'icon',
    'label',
    'tone' => 'default',
])

@php
// The action buttons in a dense table row: an icon, a tooltip, and no text.
//
// x-ui.button is the wrong shape here — it carries text padding and a gap, and a
// row of five labelled buttons makes the table wider than the screen. So this was
// hand-rolled instead, ten times, as `<button class="rounded p-1 text-gray-400
// hover:text-accent-600 hover:bg-accent-50">` with an inline SVG, in the mobile
// card AND again in the desktop table AND again on the fleet page. The classes
// had already drifted between copies.
//
// `label` is not optional. An icon-only control with no accessible name is a
// button that only exists for people who can see it, and it is also the tooltip,
// so there is no version of this component that ships without one.
$tones = match ($tone) {
    'danger' => 'text-gray-400 hover:text-red-600 hover:bg-red-50 focus-visible:ring-red-500',
    default  => 'text-gray-400 hover:text-accent-600 hover:bg-accent-50 focus-visible:ring-accent-500',
};
@endphp

<button
    type="button"
    {{ $attributes->merge([
        'class' => "rounded p-1 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-40 disabled:cursor-not-allowed {$tones}",
        'title' => $label,
        'aria-label' => $label,
    ]) }}
>
    <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4" aria-hidden="true" />
</button>
