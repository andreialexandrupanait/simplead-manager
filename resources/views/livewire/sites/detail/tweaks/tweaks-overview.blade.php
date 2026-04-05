<div>
    <x-ui.page-header title="{{ __('Tweaks') }}" subtitle="{{ __('WordPress performance and site control optimizations') }}" />

    @include('livewire.sites.detail.tweaks.partials.tweaks-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />

    {{-- Stats Summary --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $this->enabledCount }}</p>
                <p class="text-sm text-gray-500">{{ __('Tweaks Enabled') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->appliedCount }}</p>
                <p class="text-sm text-gray-500">{{ __('Successfully Applied') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->failedCount > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $this->failedCount }}</p>
                <p class="text-sm text-gray-500">{{ __('Failed') }}</p>
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
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Performance') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Heartbeat, revisions, image optimization, and frontend cleanup.') }}</p>
                </div>
                <x-icons.zap class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $perfEnabled }} {{ __('enabled') }}</span>
                <span class="text-green-600">{{ $perfApplied }} {{ __('applied') }}</span>
                @if($perfFailed > 0)
                    <span class="text-red-600">{{ $perfFailed }} {{ __('failed') }}</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.performance', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    {{ __('Configure') }} &rarr;
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
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Site Control') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Updates, comments, feeds, embeds, Gutenberg, and redirects.') }}</p>
                </div>
                <x-icons.settings class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $controlEnabled }} {{ __('enabled') }}</span>
                <span class="text-green-600">{{ $controlApplied }} {{ __('applied') }}</span>
                @if($controlFailed > 0)
                    <span class="text-red-600">{{ $controlFailed }} {{ __('failed') }}</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.site-control', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    {{ __('Configure') }} &rarr;
                </a>
            </div>
        </x-ui.card>

        @php
            $auxSettings = $this->settingsByCategory['admin_ux'] ?? collect();
            $auxEnabled = $auxSettings->where('is_enabled', true)->count();
            $auxApplied = $auxSettings->where('is_enabled', true)->whereNotNull('applied_at')->whereNull('failed_at')->count();
            $auxFailed = $auxSettings->whereNotNull('failed_at')->count();
        @endphp
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Admin UX') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Customize the WordPress admin experience.') }}</p>
                </div>
                <x-icons.layout class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $auxEnabled }} {{ __('enabled') }}</span>
                <span class="text-green-600">{{ $auxApplied }} {{ __('applied') }}</span>
                @if($auxFailed > 0)
                    <span class="text-red-600">{{ $auxFailed }} {{ __('failed') }}</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.admin-ux', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    {{ __('Configure') }} &rarr;
                </a>
            </div>
        </x-ui.card>

        @php
            $cmSettings = $this->settingsByCategory['content_media'] ?? collect();
            $cmEnabled = $cmSettings->where('is_enabled', true)->count();
            $cmApplied = $cmSettings->where('is_enabled', true)->whereNotNull('applied_at')->whereNull('failed_at')->count();
            $cmFailed = $cmSettings->whereNotNull('failed_at')->count();
        @endphp
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Content & Media') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Content duplication, media management, and publishing tools.') }}</p>
                </div>
                <x-icons.image class="h-5 w-5 text-purple-500 shrink-0" />
            </div>
            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                <span>{{ $cmEnabled }} {{ __('enabled') }}</span>
                <span class="text-green-600">{{ $cmApplied }} {{ __('applied') }}</span>
                @if($cmFailed > 0)
                    <span class="text-red-600">{{ $cmFailed }} {{ __('failed') }}</span>
                @endif
            </div>
            <div class="mt-3 flex items-center justify-end">
                <a href="{{ route('sites.tweaks.content-media', $site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-700">
                    {{ __('Configure') }} &rarr;
                </a>
            </div>
        </x-ui.card>

    </div>
</div>
