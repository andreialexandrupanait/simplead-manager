<div>
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4 flex items-center justify-between">
            <div>
                <h2 class="font-medium text-gray-900">Report Recommendations</h2>
                <p class="text-sm text-gray-500 mt-1">Manage recommendations that will be included in the next generated report.</p>
            </div>
            <div class="flex items-center gap-2">
                {{-- Templates dropdown --}}
                @if($templates->count() > 0)
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                            <x-icons.copy class="h-3.5 w-3.5" />
                            Templates
                        </button>
                        <div x-show="open" @click.away="open = false" x-cloak
                             class="absolute right-0 z-10 mt-1 w-56 rounded-lg border border-gray-200 bg-white shadow-lg">
                            <div class="py-1">
                                @foreach($templates as $tpl)
                                    <div class="flex items-center justify-between px-3 py-2 hover:bg-gray-50">
                                        <button wire:click="loadTemplate({{ $tpl->id }})" @click="open = false"
                                                class="text-sm text-gray-700 truncate flex-1 text-left">
                                            {{ $tpl->name }}
                                        </button>
                                        <button wire:click="deleteTemplate({{ $tpl->id }})"
                                                wire:confirm="Delete this template?"
                                                class="ml-2 text-gray-400 hover:text-red-500 flex-shrink-0">
                                            <x-icons.x class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <button wire:click="$set('showTemplateModal', true)"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                    <x-icons.hard-drive class="h-3.5 w-3.5" />
                    Save as Template
                </button>

                <x-ui.button size="sm" wire:click="regenerateSuggestions">
                    Regenerate Suggestions
                </x-ui.button>
            </div>
        </div>

        <div class="px-5 py-4">
            {{-- Existing recommendations --}}
            @if($recommendations->count() > 0)
                <div class="space-y-2 mb-4">
                    @foreach($recommendations as $index => $rec)
                        <div class="flex items-start gap-3 rounded-lg border {{ $rec->is_included ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60' }} p-3 group">
                            {{-- Include checkbox --}}
                            <div class="flex-shrink-0 pt-0.5">
                                <input type="checkbox"
                                       wire:click="toggleIncluded({{ $rec->id }})"
                                       {{ $rec->is_included ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            </div>

                            {{-- Content --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-medium text-sm text-gray-900">{{ $rec->title }}</span>
                                    <x-ui.badge :variant="match($rec->priority) { 'high' => 'red', 'medium' => 'yellow', default => 'gray' }">
                                        {{ ucfirst($rec->priority) }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="blue">{{ ucfirst($rec->category) }}</x-ui.badge>
                                    @if($rec->is_auto_generated)
                                        <span class="text-xs text-gray-400">auto</span>
                                    @endif
                                </div>
                                <p class="text-sm text-gray-500">{{ $rec->description }}</p>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition">
                                @if($index > 0)
                                    <button wire:click="moveUp({{ $rec->id }})"
                                            class="text-gray-400 hover:text-gray-600 p-1" title="Move up">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                    </button>
                                @endif
                                @if(!$loop->last)
                                    <button wire:click="moveDown({{ $rec->id }})"
                                            class="text-gray-400 hover:text-gray-600 p-1" title="Move down">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                @endif
                                <button wire:click="removeRecommendation({{ $rec->id }})"
                                        class="text-gray-400 hover:text-red-500 p-1" title="Remove">
                                    <x-icons.x class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-6 text-gray-400 mb-4">
                    <p class="text-sm">No recommendations yet. Click "Regenerate Suggestions" or add a custom one below.</p>
                </div>
            @endif

            {{-- Add new recommendation form --}}
            <div class="rounded-lg border border-dashed border-gray-300 p-4">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">Add Custom Recommendation</p>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                        <x-ui.input type="text" wire:model="newRecTitle" placeholder="Recommendation title" />
                        @error('newRecTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                            <x-ui.select wire:model="newRecPriority">
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </x-ui.select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <x-ui.select wire:model="newRecCategory">
                                <option value="technical">Technical</option>
                                <option value="performance">Performance</option>
                                <option value="seo">SEO</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea wire:model="newRecDescription" rows="2"
                              placeholder="Detailed recommendation description"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                    @error('newRecDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-ui.button wire:click="addCustomRecommendation" size="sm">
                    Add Recommendation
                </x-ui.button>
            </div>
        </div>
    </div>

    {{-- Save as Template Modal --}}
    @if($showTemplateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showTemplateModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Save as Template</h3>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
                    <x-ui.input type="text" wire:model="templateName" placeholder="e.g. Standard monthly recs" />
                    @error('templateName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-4 flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="$set('showTemplateModal', false)">Cancel</x-ui.button>
                    <x-ui.button wire:click="saveAsTemplate">Save</x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
