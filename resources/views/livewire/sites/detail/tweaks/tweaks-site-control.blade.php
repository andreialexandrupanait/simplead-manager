<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />

    {{-- Updates Control --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Updates & Maintenance</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">Disable All Auto-Updates</p>
                        <p class="text-xs text-gray-500">Prevent WordPress from automatically updating core, plugins, and themes.</p>
                        @if($toggles['disable_all_updates'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['disable_all_updates'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['disable_all_updates'] ?? false"
                    wire:click="toggleSetting('disable_all_updates')"
                />
            </div>

            @if($toggles['disable_all_updates'] ?? false)
                <x-ui.alert variant="warning" class="ml-4">
                    Auto-updates are disabled. Make sure to manage updates manually through the Plugins & Updates page.
                </x-ui.alert>
            @endif
        </div>
    </x-ui.card>

    {{-- Content & Interaction --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Content & Interaction</h3>
        <div class="space-y-3">
            @php
                $contentSettings = [
                    'disable_comments' => ['label' => 'Disable Comments', 'desc' => 'Remove all comment functionality sitewide: forms, admin menu, and meta boxes.'],
                    'disable_feeds' => ['label' => 'Disable RSS Feeds', 'desc' => 'Disable all RSS/Atom feed endpoints and remove feed links from the header.'],
                    'disable_embeds' => ['label' => 'Disable Embeds', 'desc' => 'Remove WordPress oEmbed functionality, discovery links, and embed scripts.'],
                ];
            @endphp

            @foreach($contentSettings as $key => $info)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $info['label'] }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $info['desc'] }}</p>
                            @if($toggles[$key] ?? false)
                                <x-security.setting-status :status="$settingStatuses[$key] ?? null" />
                            @endif
                        </div>
                    </div>
                    <x-ui.toggle
                        :enabled="$toggles[$key] ?? false"
                        wire:click="toggleSetting('{{ $key }}')"
                    />
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Editor & Archives --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Editor & Archives</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">Disable Gutenberg Block Editor</p>
                        <p class="text-xs text-gray-500">Revert to the Classic Editor for all post types and remove block styles.</p>
                        @if($toggles['disable_gutenberg'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['disable_gutenberg'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['disable_gutenberg'] ?? false"
                    wire:click="toggleSetting('disable_gutenberg')"
                />
            </div>

            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">Disable Author Archives</p>
                        <p class="text-xs text-gray-500">Return 404 for author archive pages to prevent username discovery.</p>
                        @if($toggles['disable_author_archives'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['disable_author_archives'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['disable_author_archives'] ?? false"
                    wire:click="toggleSetting('disable_author_archives')"
                />
            </div>
        </div>
    </x-ui.card>

    {{-- Redirects --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">Redirects</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">Redirect 404 to Homepage</p>
                        <p class="text-xs text-gray-500">Automatically redirect all 404 (Not Found) pages to the homepage with a 301 redirect.</p>
                        @if($toggles['redirect_404'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['redirect_404'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['redirect_404'] ?? false"
                    wire:click="toggleSetting('redirect_404')"
                />
            </div>
        </div>
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">You have unsaved changes</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                Save Changes
            </x-ui.button>
        </div>
    @endif
</div>
