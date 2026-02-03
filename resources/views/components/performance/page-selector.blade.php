@props(['pages', 'selectedPageId' => null, 'showAddPage' => false])

<div class="mb-6">
    <div class="flex flex-wrap items-center gap-2">
        {{-- All / Primary tab --}}
        <button
            wire:click="selectPage(null)"
            class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                {{ $selectedPageId === null ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
        >
            Primary
        </button>

        {{-- Page tabs --}}
        @foreach($pages as $page)
            <div class="group relative flex items-center">
                <button
                    wire:click="selectPage({{ $page->id }})"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                        {{ $selectedPageId === $page->id ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                >
                    {{ $page->label }}
                    @if($page->is_primary)
                        <span class="ml-1 text-xs text-purple-400">*</span>
                    @endif
                </button>
                {{-- Page actions dropdown --}}
                <x-ui.dropdown align="right" width="48">
                    <x-slot:trigger>
                        <button class="ml-0.5 hidden rounded p-0.5 text-gray-400 hover:text-gray-600 group-hover:inline-flex">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"/></svg>
                        </button>
                    </x-slot:trigger>
                    @unless($page->is_primary)
                        <button wire:click="setPrimaryPage({{ $page->id }})" class="block w-full px-3 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-50">Set as Primary</button>
                    @endunless
                    <button wire:click="removePage({{ $page->id }})" wire:confirm="Remove this page?" class="block w-full px-3 py-1.5 text-left text-xs text-red-600 hover:bg-red-50">Remove</button>
                </x-ui.dropdown>
            </div>
        @endforeach

        {{-- Add Page button --}}
        <button
            wire:click="$toggle('showAddPage')"
            class="rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-500 hover:border-purple-400 hover:text-purple-600"
        >
            + Add Page
        </button>
    </div>

    {{-- Inline add form --}}
    @if($showAddPage)
        <div class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div class="flex-1" style="min-width: 140px;">
                <label class="block text-xs font-medium text-gray-600">Label</label>
                <input type="text" wire:model="newPageLabel" placeholder="e.g. Shop" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
            </div>
            <div class="flex-[2]" style="min-width: 220px;">
                <label class="block text-xs font-medium text-gray-600">URL</label>
                <input type="url" wire:model="newPageUrl" placeholder="https://example.com/shop" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
            </div>
            <div class="flex gap-2">
                <button wire:click="addPage" class="rounded-md bg-purple-600 px-3 py-2 text-sm font-medium text-white hover:bg-purple-700">Add</button>
                <button wire:click="$set('showAddPage', false)" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
            </div>
        </div>
        @error('newPageLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('newPageUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
