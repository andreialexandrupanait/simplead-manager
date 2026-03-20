<div>
    @include('livewire.sites.detail.tweaks.partials.tweaks-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />

    {{-- Stats Summary --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $this->enabledCount }}</p>
                <p class="text-sm text-gray-500">Tweaks Enabled</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->appliedCount }}</p>
                <p class="text-sm text-gray-500">Successfully Applied</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->failedCount > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $this->failedCount }}</p>
                <p class="text-sm text-gray-500">Failed</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Category Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Performance</h3>
                    <p class="mt-1 text-sm text-gray-500">Heartbeat, revisions, image optimization, and frontend cleanup.</p>
                </div>
                <x-icons.zap class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            @php
                $perfCount = ($this->settingsByCategory['performance'] ?? collect())->where('is_enabled', true)->count();
            @endphp
            <div class="mt-4 flex items-center justify-between">
                <span class="text-sm text-gray-500">{{ $perfCount }} active</span>
                <a href="{{ route('sites.tweaks.performance', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    Configure &rarr;
                </a>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Site Control</h3>
                    <p class="mt-1 text-sm text-gray-500">Updates, comments, feeds, embeds, Gutenberg, and redirects.</p>
                </div>
                <x-icons.settings class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            @php
                $controlCount = ($this->settingsByCategory['site_control'] ?? collect())->where('is_enabled', true)->count();
            @endphp
            <div class="mt-4 flex items-center justify-between">
                <span class="text-sm text-gray-500">{{ $controlCount }} active</span>
                <a href="{{ route('sites.tweaks.site-control', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    Configure &rarr;
                </a>
            </div>
        </x-ui.card>
    </div>
</div>
