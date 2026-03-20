<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])
    @include('livewire.sites.detail.security.partials.monitoring-sub-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="scan" :jobs="$trackedJobs" title="Running security scan..." />
    <x-ui.job-progress job-key="integrity" :jobs="$trackedJobs" title="Checking core file integrity..." />

    {{-- Security Score Header --}}
    <div class="mb-6">
        <x-ui.card>
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-6">
                    <x-security.score-circle :score="$this->latestScan?->score" />

                    <div>
                        @if($this->latestScan)
                            <p class="text-lg font-semibold text-gray-900">{{ $this->latestScan->score_label }}</p>
                            <p class="mt-1 text-xs text-gray-500">
                                Last scanned: {{ $this->latestScan->scanned_at->diffForHumans() }}
                            </p>
                        @else
                            <p class="text-lg font-semibold text-gray-500">No Scan Yet</p>
                            <p class="text-sm text-gray-400">Run a security scan to get your score.</p>
                        @endif
                    </div>
                </div>

                <div>
                    <x-ui.button variant="primary" size="sm" wire:click="scanNow" wire:loading.attr="disabled">
                        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="scanNow" />
                        Scan Now
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Security Checks --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">Security Checks</h3>
        <div class="space-y-3">
            {{-- 1. WordPress Version --}}
            @php
                $wpUpToDate = !$this->site->core_update_version;
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3">
                    @if($wpUpToDate)
                        <x-icons.check-circle class="h-5 w-5 text-green-500 shrink-0" />
                    @else
                        <x-icons.x-circle class="h-5 w-5 text-red-500 shrink-0" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-900">WordPress Version</p>
                        <p class="text-xs text-gray-500">
                            @if($wpUpToDate)
                                Running latest version ({{ $this->site->wp_version }})
                            @else
                                Update available: {{ $this->site->wp_version }} &rarr; {{ $this->site->core_update_version }}
                            @endif
                        </p>
                    </div>
                </div>
                @if(!$wpUpToDate)
                    <a href="{{ route('sites.plugins', $this->site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800" wire:navigate>Update</a>
                @endif
            </div>

            {{-- 2. Vulnerable Plugins --}}
            @php
                $vulnCount = $this->vulnerabilities->count();
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3">
                    @if($vulnCount === 0)
                        <x-icons.check-circle class="h-5 w-5 text-green-500 shrink-0" />
                    @else
                        <x-icons.x-circle class="h-5 w-5 text-red-500 shrink-0" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-900">Plugin Vulnerabilities</p>
                        <p class="text-xs text-gray-500">
                            @if($vulnCount === 0)
                                No known vulnerabilities detected
                            @else
                                {{ $vulnCount }} vulnerable {{ Str::plural('plugin', $vulnCount) }} found
                            @endif
                        </p>
                    </div>
                </div>
                @if($vulnCount > 0)
                    <a href="{{ route('sites.plugins', $this->site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800" wire:navigate>View</a>
                @endif
            </div>

            {{-- 3. Core File Integrity --}}
            @php
                $coreCheck = $this->latestCoreCheck;
                $coreOk = $coreCheck && ($coreCheck->modified_files_count ?? 0) === 0;
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3">
                    @if(!$coreCheck)
                        <x-icons.clock class="h-5 w-5 text-gray-400 shrink-0" />
                    @elseif($coreOk)
                        <x-icons.check-circle class="h-5 w-5 text-green-500 shrink-0" />
                    @else
                        <x-icons.x-circle class="h-5 w-5 text-red-500 shrink-0" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-900">Core File Integrity</p>
                        <p class="text-xs text-gray-500">
                            @if(!$coreCheck)
                                Not yet checked
                            @elseif($coreOk)
                                All core files intact
                            @else
                                {{ $coreCheck->modified_files_count }} modified {{ Str::plural('file', $coreCheck->modified_files_count) }} detected
                            @endif
                        </p>
                    </div>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="checkCoreIntegrityNow" wire:loading.attr="disabled" wire:target="checkCoreIntegrityNow">
                    <span wire:loading.remove wire:target="checkCoreIntegrityNow">Check</span>
                    <span wire:loading wire:target="checkCoreIntegrityNow">Checking...</span>
                </x-ui.button>
            </div>

            {{-- 4. Debug Mode --}}
            @php
                $debugOff = !($this->site->wp_debug ?? false);
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3">
                    @if($debugOff)
                        <x-icons.check-circle class="h-5 w-5 text-green-500 shrink-0" />
                    @else
                        <x-icons.x-circle class="h-5 w-5 text-yellow-500 shrink-0" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-900">Debug Mode</p>
                        <p class="text-xs text-gray-500">
                            @if($debugOff)
                                WP_DEBUG is disabled
                            @else
                                WP_DEBUG is enabled (should be off in production)
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- 5. SSL Certificate --}}
            @php
                $ssl = $this->sslCertificate;
                $sslOk = $ssl && $ssl->status === 'valid';
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3">
                    @if(!$ssl)
                        <x-icons.clock class="h-5 w-5 text-gray-400 shrink-0" />
                    @elseif($sslOk)
                        <x-icons.check-circle class="h-5 w-5 text-green-500 shrink-0" />
                    @else
                        <x-icons.x-circle class="h-5 w-5 text-red-500 shrink-0" />
                    @endif
                    <div>
                        <p class="text-sm font-medium text-gray-900">SSL Certificate</p>
                        <p class="text-xs text-gray-500">
                            @if(!$ssl)
                                Not yet checked
                            @elseif($sslOk)
                                Valid &middot; {{ $ssl->days_remaining }} days remaining &middot; {{ $ssl->issuer ?? 'Unknown issuer' }}
                            @else
                                {{ ucfirst(str_replace('_', ' ', $ssl->status)) }}
                                @if($ssl->days_remaining !== null)
                                    &middot; {{ $ssl->days_remaining }} days remaining
                                @endif
                            @endif
                        </p>
                    </div>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="checkSslNow" wire:loading.attr="disabled" wire:target="checkSslNow">
                    <span wire:loading.remove wire:target="checkSslNow">Check</span>
                    <span wire:loading wire:target="checkSslNow">Checking...</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    {{-- Active Issues (if any) --}}
    @if($this->activeIssues->count() > 0)
        <div class="mt-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Active Issues ({{ $this->activeIssues->count() }})</h3>
                <div class="space-y-3">
                    @foreach($this->activeIssues as $issue)
                        <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-100 p-3">
                            <div class="flex items-start gap-3">
                                <x-ui.badge :variant="$issue->severity_color" class="mt-0.5 shrink-0">
                                    {{ ucfirst($issue->severity) }}
                                </x-ui.badge>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $issue->title }}</p>
                                    @if($issue->description)
                                        <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($issue->description, 120) }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <x-ui.button variant="secondary" size="sm" wire:click="resolveIssue({{ $issue->id }})" wire:loading.attr="disabled">
                                    Fix
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="ignoreIssue({{ $issue->id }})" wire:loading.attr="disabled">
                                    Ignore
                                </x-ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
