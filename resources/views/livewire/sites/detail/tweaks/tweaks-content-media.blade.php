<div>
    <x-ui.page-header title="{{ __('Content & Media') }}" subtitle="{{ __('Content duplication, media management, and publishing tools') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-modal-copy-settings')">
                {{ __('Copy to Sites') }}
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.tweaks.partials.tweaks-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Content Duplication --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Content Duplication') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Enable Content Duplication') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Add a "Duplicate" action to posts, pages, and custom post types in WordPress admin.') }}</p>
                    @if($toggles['content_duplication'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['content_duplication'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['content_duplication'] ?? false"
                    wire:click="toggleSetting('content_duplication')"
                />
            </div>

            @if($toggles['content_duplication'] ?? false)
                <div class="ml-6 space-y-4 bg-gray-50 rounded-lg p-4">
                    {{-- Post Types --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Post Types') }}</label>
                        <div class="flex flex-wrap gap-3">
                            @foreach(['post', 'page'] as $type)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox"
                                        {{ in_array($type, $duplicationPostTypes) ? 'checked' : '' }}
                                        wire:click="toggleDuplicationPostType('{{ $type }}')"
                                        class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                    <span class="text-sm text-gray-700">{{ ucfirst($type) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Copy Options --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Copy Options') }}</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="duplicationCopyTaxonomies" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                <span class="text-sm text-gray-700">{{ __('Copy taxonomies (categories, tags)') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="duplicationCopyMeta" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                <span class="text-sm text-gray-700">{{ __('Copy custom fields (post meta)') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="duplicationCopyFeaturedImage" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                <span class="text-sm text-gray-700">{{ __('Copy featured image') }}</span>
                            </label>
                        </div>
                    </div>

                    {{-- Duplicate Status --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Duplicate Status') }}</label>
                        <select wire:model.live="duplicationStatus" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500 max-w-xs">
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="publish">{{ __('Published') }}</option>
                            <option value="private">{{ __('Private') }}</option>
                        </select>
                    </div>

                    {{-- Title Prefix/Suffix --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Title Prefix') }}</label>
                            <input type="text" wire:model.live="duplicationTitlePrefix" placeholder="{{ __('e.g. Copy of') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Title Suffix') }}</label>
                            <input type="text" wire:model.live="duplicationTitleSuffix" placeholder="{{ __('e.g. (Copy)') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                        </div>
                    </div>

                    {{-- Redirect After --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('After Duplication') }}</label>
                        <select wire:model.live="duplicationRedirectAfter" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500 max-w-xs">
                            <option value="edit">{{ __('Open the editor') }}</option>
                            <option value="list">{{ __('Return to list') }}</option>
                        </select>
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Media Management --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Media Management') }}</h3>
        <div class="space-y-3">
            @php
                $mediaSettings = [
                    'media_replacement' => ['label' => __('Media Replacement'), 'desc' => __('Replace media files while keeping the same URL and attachment ID.')],
                    'svg_upload' => ['label' => __('SVG Upload Support'), 'desc' => __('Allow SVG file uploads with automatic sanitization for security.')],
                    'avif_upload' => ['label' => __('AVIF Upload Support'), 'desc' => __('Allow AVIF image format uploads for better compression.')],
                    'media_visibility_control' => ['label' => __('Media Visibility Control'), 'desc' => __('Non-admin users can only see their own uploaded media files.')],
                ];
            @endphp

            @foreach($mediaSettings as $key => $info)
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

    {{-- Content Features --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Content Features') }}</h3>
        <div class="space-y-3">
            @php
                $contentFeatures = [
                    'external_permalinks' => ['label' => __('External Permalinks'), 'desc' => __('Set an external URL for any post or page — visitors are redirected with 301.')],
                    'open_external_links_new_tab' => ['label' => __('Open External Links in New Tab'), 'desc' => __('Automatically add target="_blank" to external links in post content.')],
                    'auto_publish_missed_schedule' => ['label' => __('Auto-Publish Missed Schedule'), 'desc' => __('Automatically publish posts that missed their scheduled publish time.')],
                ];
            @endphp

            @foreach($contentFeatures as $key => $info)
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

    {{-- Content Order --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Content Ordering') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Custom Content Order') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Add a sortable menu_order column to post lists for custom ordering.') }}</p>
                    @if($toggles['content_order'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['content_order'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['content_order'] ?? false"
                    wire:click="toggleSetting('content_order')"
                />
            </div>

            @if($toggles['content_order'] ?? false)
                <div class="ml-6 bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Post Types') }}</label>
                    <div class="flex flex-wrap gap-3">
                        @foreach(['post', 'page'] as $type)
                            <label class="flex items-center gap-2">
                                <input type="checkbox"
                                    {{ in_array($type, $contentOrderPostTypes) ? 'checked' : '' }}
                                    wire:click="toggleContentOrderPostType('{{ $type }}')"
                                    class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                <span class="text-sm text-gray-700">{{ ucfirst($type) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">{{ __('You have unsaved changes') }}</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    @endif

    <livewire:components.copy-settings-modal :source-site="$site" :show-security-option="false" :show-tweaks-option="true" :show-modules-option="false" />
</div>
