@php
    $chipClasses = [
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        'red' => 'bg-red-100 text-red-700 ring-red-500/20',
        'orange' => 'bg-orange-100 text-orange-700 ring-orange-500/20',
        'amber' => 'bg-amber-100 text-amber-700 ring-amber-500/20',
        'green' => 'bg-green-100 text-green-700 ring-green-500/20',
        'teal' => 'bg-teal-100 text-teal-700 ring-teal-500/20',
        'blue' => 'bg-blue-100 text-blue-700 ring-blue-500/20',
        'indigo' => 'bg-indigo-100 text-indigo-700 ring-indigo-500/20',
        'purple' => 'bg-purple-100 text-purple-700 ring-purple-500/20',
        'pink' => 'bg-pink-100 text-pink-700 ring-pink-500/20',
    ];
    $assigned = $this->assignedTagIds;
@endphp

<div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-1">Tags</h3>
    <p class="text-xs text-gray-400 mb-3">Group this site (prod/staging, plan tier, client segment). Click to toggle.</p>

    <div class="flex flex-wrap gap-2 mb-4">
        @forelse($this->allTags as $tag)
            @php $isOn = in_array($tag->id, $assigned, true); @endphp
            <button type="button" wire:click="toggleTag({{ $tag->id }})"
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition
                    {{ $chipClasses[$tag->color] ?? $chipClasses['gray'] }} {{ $isOn ? '' : 'opacity-40 hover:opacity-70' }}">
                @if($isOn)<span aria-hidden="true">✓</span>@endif
                {{ $tag->name }}
            </button>
        @empty
            <span class="text-xs text-gray-400">No tags yet — create one below.</span>
        @endforelse
    </div>

    <div class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">New tag</label>
            <input type="text" wire:model="newTagName" wire:keydown.enter="createTag" placeholder="e.g. staging"
                class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500" maxlength="50">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Color</label>
            <select wire:model="newTagColor" class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                @foreach(\App\Models\Tag::COLORS as $color)
                    <option value="{{ $color }}">{{ ucfirst($color) }}</option>
                @endforeach
            </select>
        </div>
        <x-ui.button type="button" variant="secondary" wire:click="createTag">Add tag</x-ui.button>
    </div>
    @error('newTagName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
