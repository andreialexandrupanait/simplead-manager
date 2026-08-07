@props(['label' => null])

{{-- REFERINTA-VIZUALA .mbox — soft-background stat box: small muted key + large value. The value
     is the slot (supports mval-* colouring).

     WHICH ONE: use this INSIDE an x-ui.module-card, on the per-site overview. It is dense and
     borrows the module-card sub-palette. For a standalone figure with an icon and a tinted
     background, use x-ui.stat-card. They look similar and are not interchangeable; the criterion
     was never written down, which is why both exist and neither was an obvious default. --}}
<div {{ $attributes->merge(['class' => 'mbox']) }}>
    @if($label)<p class="k">{{ $label }}</p>@endif
    <p class="v">{{ $slot }}</p>
</div>
