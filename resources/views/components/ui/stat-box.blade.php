@props(['label' => null])

{{-- REFERINTA-VIZUALA .mbox — soft-background stat box: small muted key + large
     value. The value is the slot (supports mval-* colouring). --}}
<div {{ $attributes->merge(['class' => 'mbox']) }}>
    @if($label)<p class="k">{{ $label }}</p>@endif
    <p class="v">{{ $slot }}</p>
</div>
