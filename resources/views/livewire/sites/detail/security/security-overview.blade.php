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

    {{-- Hardening Score + Boost --}}
    <div class="mb-6">
        <x-ui.card>
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
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
                            <p class="text-sm text-gray-400">{{ __('Apply the settings on the right to start scoring.') }}</p>
                        @endif
                        @if($this->lastSyncAt)
                            <p class="mt-2 text-xs text-gray-400">{{ __('Last sync') }}: {{ \Carbon\Carbon::parse($this->lastSyncAt)->diffForHumans() }}</p>
                        @endif
                    </div>
                </div>

                {{-- Boost your score — top unapplied score-bearing settings --}}
                @if(count($this->nextActions) > 0)
                    <div class="lg:max-w-sm lg:border-l lg:border-gray-100 lg:pl-6">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Boost your score') }}</h3>
                        <ul class="mt-2 space-y-1.5">
                            @foreach($this->nextActions as $action)
                                <li>
                                    <a href="{{ route($action['route'], $site) }}" wire:navigate
                                       class="group flex items-center gap-2 text-sm text-gray-700 hover:text-accent-600">
                                        <span class="inline-flex w-10 shrink-0 justify-center rounded-full bg-green-50 px-1.5 py-0.5 text-xs font-semibold text-green-700">
                                            +{{ $action['weight'] }}
                                        </span>
                                        <span class="truncate">{{ $action['label'] }}</span>
                                        <x-icons.chevron-right class="h-3.5 w-3.5 shrink-0 text-gray-300 group-hover:text-accent-500" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Needs Attention Alert --}}
    @if($this->attentionItems->isNotEmpty())
        <div class="mb-6" id="needs-attention">
            <x-ui.card>
                <div class="flex items-start gap-3">
                    <x-icons.alert-triangle class="h-5 w-5 text-yellow-500 shrink-0 mt-0.5" />
                    <div class="flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('Needs Attention') }}</h3>
                            <x-ui.button variant="ghost" size="sm" wire:click="repushSettings" wire:loading.attr="disabled" wire:target="repushSettings">
                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="repushSettings" />
                                <x-icons.refresh-cw class="h-3.5 w-3.5" wire:loading.class="hidden" wire:target="repushSettings" />
                                {{ __('Re-push settings') }}
                            </x-ui.button>
                        </div>
                        <div class="mt-2 space-y-1.5">
                            @foreach($this->attentionItems as $item)
                                <a href="{{ route($item['route'], $site) }}" wire:navigate
                                   class="group flex items-center gap-2 text-sm text-gray-600 hover:text-accent-600">
                                    <span>{{ $item['label'] }}</span>
                                    @if($item['failed'] > 0)
                                        <x-ui.badge variant="red">{{ $item['failed'] }} {{ __('failed') }}</x-ui.badge>
                                    @endif
                                    @if($item['pending'] > 0)
                                        <x-ui.badge variant="yellow">{{ $item['pending'] }} {{ __('pending') }}</x-ui.badge>
                                    @endif
                                    <x-icons.chevron-right class="h-3.5 w-3.5 text-gray-300 group-hover:text-accent-500" />
                                </a>
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
                'hardening' => ['label' => __('WordPress Hardening'), 'route' => 'sites.security.hardening', 'icon' => 'shield'],
                'htaccess' => ['label' => '.htaccess Rules', 'route' => 'sites.security.hardening', 'icon' => 'file-text'],
                'login' => ['label' => __('Login Protection'), 'route' => 'sites.security.login', 'icon' => 'shield-alert'],
                'captcha' => ['label' => 'CAPTCHA', 'route' => 'sites.security.captcha', 'icon' => 'puzzle'],
                'ip_management' => ['label' => __('IP Management'), 'route' => 'sites.security.ip-management', 'icon' => 'globe'],
            ];
        @endphp

        @foreach($securityCategories as $catKey => $catInfo)
            @include('livewire.sites.detail.security.partials.category-card', [
                'site' => $site,
                'label' => $catInfo['label'],
                'route' => $catInfo['route'],
                'icon' => $catInfo['icon'],
                'settings' => $this->settingsByCategory->get($catKey, collect()),
            ])
        @endforeach

        {{-- Scanning — open issues + last scan --}}
        <a href="{{ route('sites.security.scanning', $site) }}">
            <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $this->scanSummary['openCriticalHigh'] > 0 ? 'bg-red-50 text-red-600' : ($this->scanSummary['lastScanAt'] ? 'bg-green-50 text-green-600' : 'bg-gray-100 text-gray-500') }}">
                        <x-icons.search class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-900">{{ __('Scanning') }}</h4>
                            @if($this->scanSummary['openCriticalHigh'] > 0)
                                <x-ui.badge variant="red">{{ $this->scanSummary['openCriticalHigh'] }} {{ __('open issues') }}</x-ui.badge>
                            @elseif($this->scanSummary['lastScanAt'])
                                <x-ui.badge variant="green">{{ __('No open issues') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            @if($this->scanSummary['lastScanAt'])
                                {{ __('Last scan') }} {{ \Carbon\Carbon::parse($this->scanSummary['lastScanAt'])->diffForHumans() }}
                            @else
                                {{ __('Never scanned') }}
                            @endif
                        </p>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Activity Log --}}
        @include('livewire.sites.detail.security.partials.category-card', [
            'site' => $site,
            'label' => __('Activity Log'),
            'route' => 'sites.security.activity',
            'icon' => 'activity',
            'settings' => $this->settingsByCategory->get('activity_log', collect()),
        ])

        {{-- Users --}}
        <a href="{{ route('sites.security.users', $site) }}">
            <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                        <x-icons.users class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-900">{{ __('Users') }}</h4>
                            <x-ui.badge variant="gray">{{ __('Synced') }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $this->usersSummary['total'] }} {{ __('users') }} · {{ $this->usersSummary['admins'] }} {{ __('admins') }}
                        </p>
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
                'performance' => ['label' => __('Performance'), 'route' => 'sites.tweaks.performance', 'icon' => 'zap'],
                'site_control' => ['label' => __('Site Control'), 'route' => 'sites.tweaks.site-control', 'icon' => 'sliders'],
                'admin_ux' => ['label' => __('Admin UX'), 'route' => 'sites.tweaks.admin-ux', 'icon' => 'layout'],
                'content_media' => ['label' => __('Content & Media'), 'route' => 'sites.tweaks.content-media', 'icon' => 'image'],
            ];
        @endphp

        @foreach($tweakCategories as $catKey => $catInfo)
            @include('livewire.sites.detail.security.partials.category-card', [
                'site' => $site,
                'label' => $catInfo['label'],
                'route' => $catInfo['route'],
                'icon' => $catInfo['icon'],
                'settings' => $this->tweakSettingsByCategory->get($catKey, collect()),
            ])
        @endforeach
    </div>
</div>
