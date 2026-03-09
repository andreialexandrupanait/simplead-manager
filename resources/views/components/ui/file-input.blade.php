@props([
    'accept' => null,
    'hint' => null,
])

<label {{ $attributes->only('class')->merge(['class' => 'block']) }}>
    <input {{ $attributes->except('class')->merge([
        'type' => 'file',
        'class' => 'block w-full text-sm text-gray-500
                    file:mr-3 file:rounded-lg file:border-0
                    file:bg-purple-50 file:px-4 file:py-2
                    file:text-sm file:font-medium file:text-purple-700
                    hover:file:bg-purple-100
                    focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1
                    disabled:opacity-50 disabled:cursor-not-allowed
                    cursor-pointer',
    ]) }}
    @if($accept) accept="{{ $accept }}" @endif
    >
    @if($hint)
        <span class="mt-1 block text-xs text-gray-500">{{ $hint }}</span>
    @endif
</label>
