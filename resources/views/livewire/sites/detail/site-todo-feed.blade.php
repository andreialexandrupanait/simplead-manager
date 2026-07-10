@php
    $priorityStyles = [
        'critical' => ['dot' => 'bg-red-500', 'badge' => 'bg-red-50 text-red-700 ring-red-600/10', 'label' => 'Critical'],
        'high' => ['dot' => 'bg-orange-500', 'badge' => 'bg-orange-50 text-orange-700 ring-orange-600/10', 'label' => 'High'],
        'medium' => ['dot' => 'bg-amber-500', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/10', 'label' => 'Medium'],
        'low' => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-50 text-gray-600 ring-gray-500/10', 'label' => 'Low'],
    ];
    $todos = $this->todos;
@endphp

<div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-900">Action items</h3>
        @if(count($todos))
            <span class="text-xs text-gray-400">{{ count($todos) }} to review</span>
        @endif
    </div>

    @forelse($todos as $todo)
        @php $style = $priorityStyles[$todo['priority']] ?? $priorityStyles['low']; @endphp
        <a href="{{ $todo['route'] }}"
            class="flex items-start gap-3 rounded-lg border border-gray-100 px-3 py-2.5 mb-2 hover:bg-gray-50 transition">
            <span class="mt-1.5 h-2 w-2 flex-none rounded-full {{ $style['dot'] }}"></span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-gray-800 truncate">{{ $todo['title'] }}</p>
                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset {{ $style['badge'] }}">
                        {{ $style['label'] }}
                    </span>
                </div>
                <p class="text-xs text-gray-500">{{ $todo['description'] }}</p>
            </div>
            <span class="mt-1 text-gray-300" aria-hidden="true">→</span>
        </a>
    @empty
        <div class="flex items-center gap-2 rounded-lg bg-green-50 px-3 py-3 text-sm text-green-700 ring-1 ring-green-600/10">
            <span aria-hidden="true">✓</span> All clear — nothing needs attention on this site.
        </div>
    @endforelse
</div>
