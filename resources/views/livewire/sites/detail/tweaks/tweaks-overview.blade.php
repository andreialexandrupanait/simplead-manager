<div>
    <x-ui.page-header title="Tweaks" subtitle="WordPress performance and site control optimizations" />

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
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @php
            $perfSettings = $this->settingsByCategory['performance'] ?? collect();
            $perfEnabled = $perfSettings->where('is_enabled', true)->count();
            $perfApplied = $perfSettings->where('is_enabled', true)->whereNotNull('applied_at')->whereNull('failed_at')->count();
            $perfFailed = $perfSettings->whereNotNull('failed_at')->count();
        @endphp
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Performance</h3>
                    <p class="mt-1 text-sm text-gray-500">Heartbeat, revisions, image optimization, and frontend cleanup.</p>
                </div>
                <x-icons.zap class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $perfEnabled }} enabled</span>
                <span class="text-green-600">{{ $perfApplied }} applied</span>
                @if($perfFailed > 0)
                    <span class="text-red-600">{{ $perfFailed }} failed</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.performance', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    Configure &rarr;
                </a>
            </div>
        </x-ui.card>

        @php
            $controlSettings = $this->settingsByCategory['site_control'] ?? collect();
            $controlEnabled = $controlSettings->where('is_enabled', true)->count();
            $controlApplied = $controlSettings->where('is_enabled', true)->whereNotNull('applied_at')->whereNull('failed_at')->count();
            $controlFailed = $controlSettings->whereNotNull('failed_at')->count();
        @endphp
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Site Control</h3>
                    <p class="mt-1 text-sm text-gray-500">Updates, comments, feeds, embeds, Gutenberg, and redirects.</p>
                </div>
                <x-icons.settings class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $controlEnabled }} enabled</span>
                <span class="text-green-600">{{ $controlApplied }} applied</span>
                @if($controlFailed > 0)
                    <span class="text-red-600">{{ $controlFailed }} failed</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.site-control', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    Configure &rarr;
                </a>
            </div>
        </x-ui.card>

        {{-- Coming Soon Cards --}}
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-400">Admin UX</h3>
                    <p class="mt-1 text-sm text-gray-400">Customize the WordPress admin experience.</p>
                </div>
                <x-icons.layout class="h-5 w-5 text-gray-300 shrink-0" />
            </div>
            <div class="mt-4">
                <x-ui.badge variant="yellow">Coming Soon</x-ui.badge>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-400">Content & Media</h3>
                    <p class="mt-1 text-sm text-gray-400">Media optimization and content management settings.</p>
                </div>
                <x-icons.image class="h-5 w-5 text-gray-300 shrink-0" />
            </div>
            <div class="mt-4">
                <x-ui.badge variant="yellow">Coming Soon</x-ui.badge>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-400">Email</h3>
                    <p class="mt-1 text-sm text-gray-400">WordPress email configuration and delivery settings.</p>
                </div>
                <x-icons.mail class="h-5 w-5 text-gray-300 shrink-0" />
            </div>
            <div class="mt-4">
                <x-ui.badge variant="yellow">Coming Soon</x-ui.badge>
            </div>
        </x-ui.card>
    </div>
</div>
