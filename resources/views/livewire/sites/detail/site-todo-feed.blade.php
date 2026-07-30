@php
    $priorityStyles = [
        'critical' => ['dot' => 'bg-red-500', 'badge' => 'bg-red-50 text-red-700 ring-red-600/10', 'label' => 'Critical'],
        'high' => ['dot' => 'bg-orange-500', 'badge' => 'bg-orange-50 text-orange-700 ring-orange-600/10', 'label' => 'High'],
        'medium' => ['dot' => 'bg-amber-500', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/10', 'label' => 'Medium'],
        'low' => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-50 text-gray-600 ring-gray-500/10', 'label' => 'Low'],
    ];

    $todos = $this->todos;

    // A bad site can produce eight of these. Uncapped and full-width, that was
    // 500px+ of action items before anything else on the page. Show the worst
    // few and count the rest.
    $visible = array_slice($todos, 0, 4);
    $hidden = max(0, count($todos) - count($visible));

    $tone = match (true) {
        collect($todos)->contains(fn ($t) => ($t['priority'] ?? null) === 'critical') => 'bad',
        count($todos) > 0 => 'warn',
        default => 'ok',
    };
@endphp

<x-ui.module-card title="De rezolvat" icon="alert-triangle" :tone="$tone">
    @if(count($todos) > 0)
        <x-slot:actions>
            <span class="ml-auto text-xs text-gray-400">{{ count($todos) }}</span>
        </x-slot:actions>
    @endif

    @forelse($visible as $todo)
        @php $style = $priorityStyles[$todo['priority']] ?? $priorityStyles['low']; @endphp
        <a href="{{ $todo['route'] }}"
            class="flex items-start gap-2.5 rounded-lg px-2 py-2 hover:bg-gray-50 transition">
            <span class="mt-1.5 h-2 w-2 flex-none rounded-full {{ $style['dot'] }}"></span>
            <div class="min-w-0 flex-1">
                <p class="text-[13px] font-medium text-gray-800 truncate">{{ $todo['title'] }}</p>
                <p class="text-xs text-gray-500 truncate">{{ $todo['description'] }}</p>
            </div>
        </a>
    @empty
        <p class="px-2 py-2 text-[13px] text-gray-500">
            <span class="text-green-600" aria-hidden="true">✓</span>
            {{ __('Nimic de rezolvat pe acest site.') }}
        </p>
    @endforelse

    @if($hidden > 0)
        <p class="px-2 pt-1 text-xs text-gray-400">
            {{ __('și încă :n de verificat', ['n' => $hidden]) }}
        </p>
    @endif
</x-ui.module-card>
