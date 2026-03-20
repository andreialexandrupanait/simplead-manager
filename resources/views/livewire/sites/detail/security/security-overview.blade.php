<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Module not active banner --}}
    @if(!$this->isModuleActive)
        <x-ui.module-activation-banner
            title="Security monitoring is not active"
            description="Enable automatic security scans and vulnerability monitoring for this site."
            icon="shield"
        >
            <x-ui.button size="sm" wire:click="activateModule">Activate</x-ui.button>
        </x-ui.module-activation-banner>
    @endif

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />

    {{-- Hardening Score --}}
    <div class="mb-6">
        <x-ui.card>
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-6">
                    <x-security.score-circle :score="$this->securityScore" />

                    <div>
                        @if($this->securityScore !== null)
                            <p class="text-lg font-semibold text-gray-900">
                                @if($this->securityScore >= 90) Excellent
                                @elseif($this->securityScore >= 80) Good
                                @elseif($this->securityScore >= 50) Needs Attention
                                @else Critical
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-500">Hardening score based on applied settings</p>
                        @else
                            <p class="text-lg font-semibold text-gray-500">Not Configured</p>
                            <p class="text-sm text-gray-400">Configure hardening settings to get a security score.</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-3 text-sm text-gray-500">
                    @if($this->pendingCommandsCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-700">
                            {{ $this->pendingCommandsCount }} pending
                        </span>
                    @endif
                    @if($this->lastSyncAt)
                        <span>Last sync: {{ \Carbon\Carbon::parse($this->lastSyncAt)->diffForHumans() }}</span>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Needs Attention Alert --}}
    @php
        $attentionItems = collect();

        // Collect from security categories
        foreach (['hardening', 'htaccess', 'login', 'captcha', 'ip_management', 'activity_log'] as $catKey) {
            $catSettings = $this->settingsByCategory->get($catKey, collect());
            $failedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
            $pendingCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Pending)->count();
            if ($failedCount > 0 || $pendingCount > 0) {
                $attentionItems->push(['key' => $catKey, 'failed' => $failedCount, 'pending' => $pendingCount]);
            }
        }

        // Collect from tweaks categories
        foreach (['performance', 'site_control'] as $catKey) {
            $catSettings = $this->tweakSettingsByCategory->get($catKey, collect());
            $failedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
            $pendingCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Pending)->count();
            if ($failedCount > 0 || $pendingCount > 0) {
                $attentionItems->push(['key' => $catKey, 'failed' => $failedCount, 'pending' => $pendingCount]);
            }
        }

        $categoryLabels = [
            'hardening' => 'WordPress Hardening',
            'htaccess' => '.htaccess Rules',
            'login' => 'Login Protection',
            'captcha' => 'CAPTCHA',
            'ip_management' => 'IP Management',
            'activity_log' => 'Activity Log',
            'performance' => 'Performance',
            'site_control' => 'Site Control',
        ];
    @endphp

    @if($attentionItems->isNotEmpty())
        <div class="mb-6">
            <x-ui.card>
                <div class="flex items-start gap-3">
                    <x-icons.alert-triangle class="h-5 w-5 text-yellow-500 shrink-0 mt-0.5" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Needs Attention</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach($attentionItems as $item)
                                <div class="flex items-center gap-2 text-sm text-gray-600">
                                    <span>{{ $categoryLabels[$item['key']] ?? $item['key'] }}</span>
                                    @if($item['failed'] > 0)
                                        <x-ui.badge variant="red">{{ $item['failed'] }} failed</x-ui.badge>
                                    @endif
                                    @if($item['pending'] > 0)
                                        <x-ui.badge variant="yellow">{{ $item['pending'] }} pending</x-ui.badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Unified Category Status Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @php
            $allCategories = [
                'hardening' => ['label' => 'WordPress Hardening', 'icon' => 'shield', 'route' => 'sites.security.hardening', 'source' => 'security'],
                'htaccess' => ['label' => '.htaccess Rules', 'icon' => 'lock', 'route' => 'sites.security.hardening', 'source' => 'security'],
                'login' => ['label' => 'Login Protection', 'icon' => 'lock', 'route' => 'sites.security.login', 'source' => 'security'],
                'captcha' => ['label' => 'CAPTCHA', 'icon' => 'shield-alert', 'route' => 'sites.security.captcha', 'source' => 'security'],
                'ip_management' => ['label' => 'IP Management', 'icon' => 'globe', 'route' => 'sites.security.ip-management', 'source' => 'security'],
                'activity_log' => ['label' => 'Activity Log', 'icon' => 'activity', 'route' => 'sites.security.activity', 'source' => 'security'],
                'performance' => ['label' => 'Performance', 'icon' => 'zap', 'route' => 'sites.security.performance', 'source' => 'tweaks'],
                'site_control' => ['label' => 'Site Control', 'icon' => 'sliders', 'route' => 'sites.security.site-control', 'source' => 'tweaks'],
            ];
        @endphp

        @foreach($allCategories as $catKey => $catInfo)
            @php
                $catSettings = $catInfo['source'] === 'security'
                    ? $this->settingsByCategory->get($catKey, collect())
                    : $this->tweakSettingsByCategory->get($catKey, collect());
                $enabledCount = $catSettings->where('is_enabled', true)->count();
                $appliedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Applied)->count();
                $failedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
                $totalCount = $catSettings->count();
            @endphp
            <a href="{{ route($catInfo['route'], $site) }}" wire:navigate>
                <x-ui.card class="cursor-pointer hover:border-purple-200 transition-colors">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">{{ $catInfo['label'] }}</h4>
                            <p class="mt-1 text-xs text-gray-500">
                                @if($totalCount === 0)
                                    Not configured
                                @else
                                    {{ $appliedCount }}/{{ $enabledCount }} applied
                                @endif
                            </p>
                        </div>
                        <div>
                            @if($failedCount > 0)
                                <x-ui.badge variant="red">Needs Attention</x-ui.badge>
                            @elseif($appliedCount > 0 && $appliedCount === $enabledCount)
                                <x-ui.badge variant="green">Applied</x-ui.badge>
                            @elseif($enabledCount > 0)
                                <x-ui.badge variant="yellow">Pending</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">Not Configured</x-ui.badge>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </a>
        @endforeach

        {{-- Coming Soon Cards --}}
        @php
            $comingSoonCategories = [
                'admin_ux' => ['label' => 'Admin UX', 'icon' => 'layout', 'route' => 'sites.security.admin-ux'],
                'content_media' => ['label' => 'Content & Media', 'icon' => 'image', 'route' => 'sites.security.content-media'],
                'email' => ['label' => 'Email', 'icon' => 'mail', 'route' => 'sites.security.email'],
            ];
        @endphp

        @foreach($comingSoonCategories as $catKey => $catInfo)
            <a href="{{ route($catInfo['route'], $site) }}" wire:navigate>
                <x-ui.card class="cursor-pointer opacity-60 transition-colors">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-400">{{ $catInfo['label'] }}</h4>
                            <p class="mt-1 text-xs text-gray-400">Coming soon</p>
                        </div>
                        <x-ui.badge variant="yellow">Soon</x-ui.badge>
                    </div>
                </x-ui.card>
            </a>
        @endforeach
    </div>
</div>
