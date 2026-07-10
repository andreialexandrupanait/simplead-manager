<div>
    <x-ui.page-header title="{{ __('Redirects') }}" subtitle="{{ __('Manage 301/302 redirects — e.g. to fix broken links') }}">
        <x-slot:actions>
            <x-ui.wp-admin-button :site="$site" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Add form --}}
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Add redirect</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-12 sm:items-end">
            <div class="sm:col-span-4">
                <label class="block text-xs font-medium text-gray-600 mb-1">Source path</label>
                <input type="text" wire:model="sourcePath" placeholder="/old-page"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                @error('sourcePath') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-5">
                <label class="block text-xs font-medium text-gray-600 mb-1">Target URL</label>
                <input type="text" wire:model="targetUrl" placeholder="https://example.com/new-page"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                @error('targetUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Code</label>
                <select wire:model="statusCode" class="w-full rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                    <option value="301">301</option>
                    <option value="302">302</option>
                </select>
            </div>
            <div class="sm:col-span-1">
                <x-ui.button wire:click="addRedirect" class="w-full justify-center">Add</x-ui.button>
            </div>
        </div>
    </div>

    {{-- Existing rules --}}
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Active rules</h3>
        @forelse($this->redirects as $redirect)
            <div class="flex items-center gap-3 border-b border-gray-100 py-2.5 text-sm last:border-0">
                <button type="button" wire:click="toggleRedirect({{ $redirect->id }})"
                    class="h-2.5 w-2.5 flex-none rounded-full {{ $redirect->is_active ? 'bg-green-500' : 'bg-gray-300' }}"
                    title="{{ $redirect->is_active ? 'Active — click to disable' : 'Disabled — click to enable' }}"></button>
                <code class="text-gray-700">{{ $redirect->source_path }}</code>
                <span class="text-gray-300">→</span>
                <span class="min-w-0 flex-1 truncate text-gray-600">{{ $redirect->target_url }}</span>
                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ $redirect->status_code }}</span>
                <button type="button" wire:click="deleteRedirect({{ $redirect->id }})"
                    wire:confirm="Delete this redirect?" class="text-gray-400 hover:text-red-600">✕</button>
            </div>
        @empty
            <p class="text-sm text-gray-400">No redirects yet.</p>
        @endforelse
    </div>

    {{-- Broken-link suggestions --}}
    @if($this->brokenLinks->isNotEmpty())
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-1">Broken links found</h3>
            <p class="text-xs text-gray-400 mb-3">From the latest SEO crawl. Click to prefill a redirect for one.</p>
            @foreach($this->brokenLinks as $link)
                <div class="flex items-center gap-3 border-b border-gray-100 py-2 text-sm last:border-0">
                    <span class="min-w-0 flex-1 truncate text-gray-600">{{ $link->target_url }}</span>
                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">{{ $link->status_code ?? 'broken' }}</span>
                    <x-ui.button size="sm" variant="secondary" wire:click="prefillFromBroken(@js($link->target_url))">Fix</x-ui.button>
                </div>
            @endforeach
        </div>
    @endif
</div>
