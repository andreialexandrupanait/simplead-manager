@props(['label' => null])

{{-- REFERINTA-VIZUALA .mrow — soft-background label/value pill. The value is the
     slot so callers can drop in coloured text (mval-*), badges, icons, etc. --}}
<div {{ $attributes->merge(['class' => 'mrow']) }}>
    <span class="lb">{{ $label }}</span>
    <span>{{ $slot }}</span>
</div>
