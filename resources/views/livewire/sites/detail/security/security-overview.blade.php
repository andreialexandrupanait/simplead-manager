<div>
    <x-ui.page-header title="{{ __('Security & Tweaks') }}" subtitle="{{ __('Security posture and WordPress behaviour, managed in one place') }}" />

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Module not active banner --}}
    @if(!$this->isModuleActive)
        <x-ui.module-activation-banner
            title="{{ __('Security monitoring is not active') }}"
            description="{{ __('Enable automatic security scans and vulnerability monitoring for this site.') }}"
            icon="shield"
        >
            <x-ui.button size="sm" wire:click="activateModule">{{ __('Activate') }}</x-ui.button>
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
                                @if($this->securityScore >= 90) {{ __('Excellent') }}
                                @elseif($this->securityScore >= 80) {{ __('Good') }}
                                @elseif($this->securityScore >= 50) {{ __('Needs Attention') }}
                                @else {{ __('Critical') }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-500">{{ __('Hardening score based on applied settings') }}</p>
                        @else
                            <p class="text-lg font-semibold text-gray-500">{{ __('Not Configured') }}</p>
                            <p class="text-sm text-gray-400">{{ __('Configure hardening settings to get a security score.') }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-3 text-sm text-gray-500">
                    @if($this->lastSyncAt)
                        <span>{{ __('Last sync') }}: {{ \Carbon\Carbon::parse($this->lastSyncAt)->diffForHumans() }}</span>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Needs Attention Alert --}}
    @if($this->attentionItems->isNotEmpty())
        <div class="mb-6" id="needs-attention">
            <x-ui.card>
                <div class="flex items-start gap-3">
                    <x-icons.alert-triangle class="h-5 w-5 text-yellow-500 shrink-0 mt-0.5" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('Needs Attention') }}</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach($this->attentionItems as $item)
                                <div class="flex items-center gap-2 text-sm text-gray-600">
                                    <span>{{ $item['label'] }}</span>
                                    @if($item['failed'] > 0)
                                        <x-ui.badge variant="red">{{ $item['failed'] }} {{ __('failed') }}</x-ui.badge>
                                    @endif
                                    @if($item['pending'] > 0)
                                        <x-ui.badge variant="yellow">{{ $item['pending'] }} {{ __('pending') }}</x-ui.badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Security band — score-bearing categories, ordered to match the tabs --}}
    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Security') }}</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @php
            $securityCategories = [
                'hardening' => ['label' => __('WordPress Hardening'), 'route' => 'sites.security.hardening'],
                'htaccess' => ['label' => '.htaccess Rules', 'route' => 'sites.security.hardening'],
                'login' => ['label' => __('Login Protection'), 'route' => 'sites.security.login'],
                'captcha' => ['label' => 'CAPTCHA', 'route' => 'sites.security.captcha'],
                'ip_management' => ['label' => __('IP Management'), 'route' => 'sites.security.ip-management'],
            ];
        @endphp

        @foreach($securityCategories as $catKey => $catInfo)
            @php
                $catSettings = $this->settingsByCategory->get($catKey, collect());
                $enabledCount = $catSettings->where('is_enabled', true)->count();
                $appliedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Applied)->count();
                $failedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
                $totalCount = $catSettings->count();
            @endphp
            <a href="{{ route($catInfo['route'], $site) }}">
                <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">{{ $catInfo['label'] }}</h4>
                            <p class="mt-1 text-xs text-gray-500">
                                @if($totalCount === 0)
                                    {{ __('Not configured') }}
                                @else
                                    {{ $appliedCount }}/{{ $enabledCount }} {{ __('applied') }}
                                @endif
                            </p>
                        </div>
                        <div>
                            @if($failedCount > 0)
                                <x-ui.badge variant="red">{{ __('Needs Attention') }}</x-ui.badge>
                            @elseif($appliedCount > 0 && $appliedCount === $enabledCount)
                                <x-ui.badge variant="green">{{ __('Applied') }}</x-ui.badge>
                            @elseif($enabledCount > 0)
                                <x-ui.badge variant="yellow">{{ __('Pending') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </a>
        @endforeach

        {{-- Scanning — open issues + last scan --}}
        <a href="{{ route('sites.security.scanning', $site) }}">
            <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900">{{ __('Scanning') }}</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            @if($this->scanSummary['lastScanAt'])
                                {{ __('Last scan') }} {{ \Carbon\Carbon::parse($this->scanSummary['lastScanAt'])->diffForHumans() }}
                            @else
                                {{ __('Never scanned') }}
                            @endif
                        </p>
                    </div>
                    <div>
                        @if($this->scanSummary['openCriticalHigh'] > 0)
                            <x-ui.badge variant="red">{{ $this->scanSummary['openCriticalHigh'] }} {{ __('open issues') }}</x-ui.badge>
                        @elseif($this->scanSummary['lastScanAt'])
                            <x-ui.badge variant="green">{{ __('No open issues') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Activity Log --}}
        @php
            $activitySettings = $this->settingsByCategory->get('activity_log', collect());
            $activityEnabled = $activitySettings->where('is_enabled', true)->count();
        @endphp
        <a href="{{ route('sites.security.activity', $site) }}">
            <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900">{{ __('Activity Log') }}</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $activityEnabled > 0 ? __('Capturing site events') : __('Not configured') }}
                        </p>
                    </div>
                    <div>
                        @if($activityEnabled > 0)
                            <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Users --}}
        <a href="{{ route('sites.security.users', $site) }}">
            <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900">{{ __('Users') }}</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $this->usersSummary['total'] }} {{ __('users') }} · {{ $this->usersSummary['admins'] }} {{ __('admins') }}
                        </p>
                    </div>
                    <div>
                        <x-ui.badge variant="gray">{{ __('Synced') }}</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>
        </a>
    </div>

    {{-- WordPress Tweaks band — same settings pipeline, no score impact --}}
    <h2 class="mb-3 mt-8 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('WordPress Tweaks') }}</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $tweakCategories = [
                'performance' => ['label' => __('Performance'), 'route' => 'sites.tweaks.performance'],
                'site_control' => ['label' => __('Site Control'), 'route' => 'sites.tweaks.site-control'],
                'admin_ux' => ['label' => __('Admin UX'), 'route' => 'sites.tweaks.admin-ux'],
                'content_media' => ['label' => __('Content & Media'), 'route' => 'sites.tweaks.content-media'],
            ];
        @endphp

        @foreach($tweakCategories as $catKey => $catInfo)
            @php
                $catSettings = $this->tweakSettingsByCategory->get($catKey, collect());
                $enabledCount = $catSettings->where('is_enabled', true)->count();
                $appliedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Applied)->count();
                $failedCount = $catSettings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
                $totalCount = $catSettings->count();
            @endphp
            <a href="{{ route($catInfo['route'], $site) }}">
                <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">{{ $catInfo['label'] }}</h4>
                            <p class="mt-1 text-xs text-gray-500">
                                @if($totalCount === 0)
                                    {{ __('Not configured') }}
                                @else
                                    {{ $appliedCount }}/{{ $enabledCount }} {{ __('applied') }}
                                @endif
                            </p>
                        </div>
                        <div>
                            @if($failedCount > 0)
                                <x-ui.badge variant="red">{{ __('Needs Attention') }}</x-ui.badge>
                            @elseif($appliedCount > 0 && $appliedCount === $enabledCount)
                                <x-ui.badge variant="green">{{ __('Applied') }}</x-ui.badge>
                            @elseif($enabledCount > 0)
                                <x-ui.badge variant="yellow">{{ __('Pending') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </a>
        @endforeach
    </div>
</div>
