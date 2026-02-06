<div>
    <x-ui.flash-alert type="success" key="success" />

    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Status Pages" subtitle="Create public status pages to communicate uptime to your clients" />
        <x-ui.button :href="route('status-pages.create')">
            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            Create Status Page
        </x-ui.button>
    </div>

    @if($this->statusPages->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No status pages"
                description="Create a status page to share uptime information with your clients."
                icon="globe"
            />
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach($this->statusPages as $page)
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg" style="background-color: {{ $page->primary_color }}20;">
                                <x-icons.globe class="h-5 w-5" style="color: {{ $page->primary_color }};" />
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900">{{ $page->title }}</h3>
                                    @if(!$page->is_public)
                                        <x-ui.badge variant="gray">Private</x-ui.badge>
                                    @endif
                                    @if($page->password_hash)
                                        <x-ui.badge variant="yellow">Password</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex items-center gap-3 text-xs text-gray-500">
                                    <span>/status/{{ $page->slug }}</span>
                                    <span>&middot;</span>
                                    <span>{{ $page->status_page_sites_count }} {{ Str::plural('site', $page->status_page_sites_count) }}</span>
                                    @if($page->incidents_count > 0)
                                        <span>&middot;</span>
                                        <span class="text-red-600">{{ $page->incidents_count }} active {{ Str::plural('incident', $page->incidents_count) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ url('/status/' . $page->slug) }}" target="_blank"
                               class="rounded p-1.5 text-gray-400 hover:text-purple-600 hover:bg-purple-50" title="View Public Page">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                            </a>
                            <a href="{{ route('status-pages.edit', $page) }}"
                               class="rounded p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100" title="Edit">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            </a>
                            <button wire:click="confirmDelete({{ $page->id }})"
                                class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50" title="Delete">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    {{-- Delete confirmation modal --}}
    <x-ui.modal name="delete-status-page">
        <h2 class="text-lg font-semibold text-gray-900">Delete Status Page</h2>
        <p class="mt-2 text-sm text-gray-600">Are you sure you want to delete this status page? This will also remove all associated incidents and updates. This action cannot be undone.</p>
        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-delete-status-page')">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteStatusPage">Delete</x-ui.button>
        </div>
    </x-ui.modal>
</div>
