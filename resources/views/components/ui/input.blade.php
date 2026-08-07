@props(['error' => null, 'errorId' => null])

@php
    $describedBy = $errorId ?? ($error ? ($attributes->get('id') ? $attributes->get('id') . '-error' : null) : null);
@endphp

{{--
    Focus used to be `focus:ring-0 focus:outline-none` with nothing but a 1px border colour change
    left to mark it — on the most-used form element in the application. A hairline that shifts from
    grey to blue is not an indicator you can find with your eyes while tabbing.

    The soft accent glow below is also closer to REFERINTA-VIZUALA, not further from it: the
    WordPress admin this app is themed after puts exactly this halo on a focused field. Flat
    surfaces were the point, not invisible focus.
--}}
<input {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px]
                placeholder:text-gray-400
                focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30
                disabled:bg-gray-50 disabled:text-gray-500
                dark:border-gray-600 dark:bg-gray-800',
    'autocomplete' => 'off',
]) }}
    @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
    @if($error) aria-invalid="true" @endif
>
