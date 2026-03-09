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

    {{-- Category Status Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @php
            $categories = [
                'hardening' => ['label' => 'WordPress Hardening', 'icon' => 'shield', 'route' => 'sites.security.hardening'],
                'htaccess' => ['label' => '.htaccess Rules', 'icon' => 'lock', 'route' => 'sites.security.hardening'],
                'login' => ['label' => 'Login Protection', 'icon' => 'lock', 'route' => 'sites.security.login'],
                'captcha' => ['label' => 'CAPTCHA', 'icon' => 'shield-alert', 'route' => 'sites.security.captcha'],
                'ip_management' => ['label' => 'IP Management', 'icon' => 'globe', 'route' => 'sites.security.ip-management'],
                'activity_log' => ['label' => 'Activity Log', 'icon' => 'activity', 'route' => 'sites.security.activity'],
            ];
        @endphp

        @foreach($categories as $catKey => $catInfo)
            @php
                $catSettings = $this->settingsByCategory->get($catKey, collect());
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
                        <div class="flex items-center gap-1">
                            @if($failedCount > 0)
                                <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>
                            @elseif($appliedCount > 0 && $appliedCount === $enabledCount)
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                            @elseif($enabledCount > 0)
                                <span class="h-2.5 w-2.5 rounded-full bg-yellow-500"></span>
                            @else
                                <span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </a>
        @endforeach
    </div>
</div>
