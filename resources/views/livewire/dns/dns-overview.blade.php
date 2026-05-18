<div>
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('DNS Monitoring') }}" subtitle="{{ __('DNS records, email security, and update history') }}" />
        <x-ui.button wire:click="recheckAll" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="recheckAll">{{ __('Recheck All') }}</span>
            <span wire:loading wire:target="recheckAll">{{ __('Queuing...') }}</span>
        </x-ui.button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Monitors') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold {{ $this->stats['with_changes'] > 0 ? 'text-accent-600' : 'text-gray-500' }}">{{ $this->stats['with_changes'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Updated') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-accent-600">{{ $this->stats['cloudflare'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Cloudflare') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold {{ $this->stats['no_spf'] > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $this->stats['no_spf'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Missing SPF') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold {{ $this->stats['no_dmarc'] > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $this->stats['no_dmarc'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Missing DMARC') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-blue-600">{{ $this->stats['recent_changes'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Updates (7d)') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Tabs + Search --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['monitors' => __('Monitors'), 'changes' => __('Recent Updates')]"
            :selected="$tab"
            wire="tab"
        />
        @if($tab === 'monitors')
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search domains...') }}" class="w-full sm:ml-auto sm:w-64" />
        @endif
    </div>

    @if($tab === 'monitors')
        <div class="space-y-3">
            @forelse($monitors as $monitor)
                @php
                    $records = $monitor->current_records ?? [];
                    $txtRecords = implode(' ', $records['TXT'] ?? []);
                    $nsRecords = implode(' ', $records['NS'] ?? []);
                    $dmarcRecords = $records['DMARC'] ?? [];
                    $dkimRecords = $records['DKIM'] ?? [];
                    $hasSpf = stripos($txtRecords, 'v=spf1') !== false;
                    $hasDmarc = ! empty($dmarcRecords);
                    $hasDkim = ! empty($dkimRecords);
                    $usesCloudflare = stripos($nsRecords, 'cloudflare') !== false;
                    $aRecords = $records['A'] ?? [];
                    $mxRecords = $records['MX'] ?? [];
                    $configuredSelectors = $monitor->dkim_selectors ?? [];
                @endphp
                <x-ui.card class="!p-0 overflow-hidden">
                    {{-- Header row --}}
                    <button wire:click="toggleExpand({{ $monitor->id }})" class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors text-left">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="shrink-0">
                                @if($monitor->has_changes)
                                    <div class="h-8 w-8 rounded-full bg-accent-100 dark:bg-accent-900/30 flex items-center justify-center" title="{{ __('DNS records updated') }}">
                                        <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </div>
                                @elseif(!empty($records))
                                    <div class="h-8 w-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                        <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                @else
                                    <div class="h-8 w-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $monitor->domain }}</span>
                                    @if($monitor->site)
                                        <span class="text-xs text-gray-400">{{ $monitor->site->name }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    @if(!empty($aRecords))
                                        <span class="text-[10px] font-mono text-gray-500">{{ implode(', ', $aRecords) }}</span>
                                    @endif
                                    @if($usesCloudflare)
                                        <x-ui.badge variant="purple" class="text-[9px]">CF</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            {{-- Email security badges --}}
                            @if(!empty($records))
                                <span class="inline-flex items-center gap-0.5 text-[10px] font-medium {{ $hasSpf ? 'text-green-600' : 'text-red-500' }}" title="SPF">SPF {{ $hasSpf ? '✓' : '✗' }}</span>
                                <span class="inline-flex items-center gap-0.5 text-[10px] font-medium {{ $hasDmarc ? 'text-green-600' : 'text-red-500' }}" title="DMARC">DMARC {{ $hasDmarc ? '✓' : '✗' }}</span>
                                <span class="inline-flex items-center gap-0.5 text-[10px] font-medium {{ $hasDkim ? 'text-green-600' : 'text-red-500' }}" title="DKIM">DKIM {{ $hasDkim ? '✓' : '✗' }}</span>
                            @endif
                            <span class="text-xs text-gray-400 ml-2">{{ $monitor->last_checked_at?->diffForHumans() ?? 'pending' }}</span>
                            <svg class="h-4 w-4 text-gray-400 transition-transform {{ $expandedMonitor === $monitor->id ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </button>

                    {{-- Expanded DNS records --}}
                    @if($expandedMonitor === $monitor->id && !empty($records))
                        <div class="border-t border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 px-4 py-3">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {{-- A Records --}}
                                @if(!empty($records['A']))
                                    <div>
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">A Records</h4>
                                        @foreach($records['A'] as $ip)
                                            <div class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $ip }}</div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- AAAA Records --}}
                                @if(!empty($records['AAAA']))
                                    <div>
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">AAAA Records</h4>
                                        @foreach($records['AAAA'] as $ip)
                                            <div class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $ip }}</div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- NS Records --}}
                                @if(!empty($records['NS']))
                                    <div>
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Nameservers</h4>
                                        @foreach($records['NS'] as $ns)
                                            <div class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $ns }}</div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- MX Records --}}
                                @if(!empty($records['MX']))
                                    <div>
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Mail (MX)</h4>
                                        @foreach($records['MX'] as $mx)
                                            @php $target = is_array($mx) ? $mx['target'] : $mx; $pri = is_array($mx) ? $mx['pri'] ?? 0 : 0; @endphp
                                            <div class="text-xs font-mono text-gray-700 dark:text-gray-300">
                                                <span class="text-gray-400">{{ $pri }}</span> {{ $target }}
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- CNAME Records --}}
                                @if(!empty($records['CNAME']))
                                    <div>
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">CNAME</h4>
                                        @foreach($records['CNAME'] as $cname)
                                            <div class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $cname }}</div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- TXT Records --}}
                                @if(!empty($records['TXT']))
                                    <div class="md:col-span-2 lg:col-span-3">
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">TXT Records</h4>
                                        @foreach($records['TXT'] as $txt)
                                            <div class="text-xs font-mono text-gray-600 dark:text-gray-400 break-all mb-1 p-1.5 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                                                @if(stripos($txt, 'v=spf1') !== false)
                                                    <span class="text-green-600 font-semibold">SPF:</span>
                                                @elseif(stripos($txt, 'google-site-verification') !== false)
                                                    <span class="text-blue-600 font-semibold">Google:</span>
                                                @elseif(stripos($txt, 'facebook-domain-verification') !== false)
                                                    <span class="text-blue-600 font-semibold">Facebook:</span>
                                                @endif
                                                {{ $txt }}
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- DMARC Records --}}
                                @if(!empty($dmarcRecords))
                                    <div class="md:col-span-2 lg:col-span-3">
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">DMARC <span class="text-gray-400 normal-case">(_dmarc.{{ $monitor->domain }})</span></h4>
                                        @foreach($dmarcRecords as $txt)
                                            <div class="text-xs font-mono text-gray-600 dark:text-gray-400 break-all mb-1 p-1.5 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                                                <span class="text-green-600 font-semibold">DMARC:</span> {{ $txt }}
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- DKIM Records --}}
                                @if(!empty($dkimRecords))
                                    <div class="md:col-span-2 lg:col-span-3">
                                        <h4 class="text-[10px] font-semibold text-gray-500 uppercase mb-1">DKIM</h4>
                                        @foreach($dkimRecords as $dkim)
                                            @php
                                                $source = $dkim['source'] ?? 'fallback';
                                                $sourceLabel = match($source) {
                                                    'cloudflare' => ['CF', 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'],
                                                    'postmark' => ['PM', 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300'],
                                                    'manual' => ['Manual', 'bg-accent-100 text-accent-700 dark:bg-accent-900/30 dark:text-accent-300'],
                                                    default => ['Auto', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
                                                };
                                            @endphp
                                            <div class="text-xs font-mono text-gray-600 dark:text-gray-400 break-all mb-1 p-1.5 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold mr-1 {{ $sourceLabel[1] }}">{{ $sourceLabel[0] }}</span>
                                                <span class="text-green-600 font-semibold">{{ $dkim['selector'] }}._domainkey:</span> {{ $dkim['value'] }}
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            {{-- Email Security Summary --}}
                            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                <div class="flex items-center justify-between mb-2 gap-2">
                                    <h4 class="text-[10px] font-semibold text-gray-500 uppercase">{{ __('Email Security') }}</h4>
                                    @if($editingSelectorsFor !== $monitor->id)
                                        <div class="flex items-center gap-3">
                                            <button wire:click="rediscoverSelectors({{ $monitor->id }})" wire:loading.attr="disabled" wire:target="rediscoverSelectors" class="text-[10px] text-accent-600 hover:text-accent-700 inline-flex items-center gap-1">
                                                <svg wire:loading.remove wire:target="rediscoverSelectors({{ $monitor->id }})" class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                <span wire:loading.remove wire:target="rediscoverSelectors({{ $monitor->id }})">{{ __('Re-discover (CF/Postmark)') }}</span>
                                                <span wire:loading wire:target="rediscoverSelectors({{ $monitor->id }})">{{ __('Discovering...') }}</span>
                                            </button>
                                            <button wire:click="editSelectors({{ $monitor->id }})" class="text-[10px] text-accent-600 hover:text-accent-700">
                                                {{ __('Configure DKIM selectors') }}
                                                @if(!empty($configuredSelectors))
                                                    <span class="text-gray-400">({{ count($configuredSelectors) }})</span>
                                                @endif
                                            </button>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <div class="flex items-center gap-1.5">
                                        @if($hasSpf)
                                            <span class="h-2 w-2 rounded-full bg-green-500"></span>
                                            <span class="text-xs text-green-700 dark:text-green-400">SPF configured</span>
                                        @else
                                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                                            <span class="text-xs text-red-600">SPF missing — email spoofing risk</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        @if($hasDmarc)
                                            <span class="h-2 w-2 rounded-full bg-green-500"></span>
                                            <span class="text-xs text-green-700 dark:text-green-400">DMARC configured</span>
                                        @else
                                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                                            <span class="text-xs text-red-600">DMARC missing — no record at _dmarc.{{ $monitor->domain }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        @if($hasDkim)
                                            <span class="h-2 w-2 rounded-full bg-green-500"></span>
                                            <span class="text-xs text-green-700 dark:text-green-400">DKIM configured ({{ collect($dkimRecords)->pluck('selector')->join(', ') }})</span>
                                        @else
                                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                                            <span class="text-xs text-red-600">DKIM not found — add your selector below if using Postmark/custom ESP</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- DKIM selector editor --}}
                                @if($editingSelectorsFor === $monitor->id)
                                    <div class="mt-3 p-3 rounded border border-accent-200 dark:border-accent-700 bg-accent-50/50 dark:bg-accent-900/10">
                                        <label class="block text-[10px] font-semibold text-gray-600 dark:text-gray-300 uppercase mb-1">{{ __('DKIM Selectors') }}</label>
                                        <p class="text-[11px] text-gray-500 mb-2">{{ __('Comma- or space-separated. Example for Postmark: 20240101pm (find yours in Postmark → Sender Signatures → DKIM).') }}</p>
                                        <div class="flex gap-2">
                                            <input
                                                type="text"
                                                wire:model="selectorsInput"
                                                placeholder="default, 20240101pm, google"
                                                class="flex-1 text-xs font-mono rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-accent-500 focus:ring-accent-500"
                                            />
                                            <x-ui.button wire:click="saveSelectors({{ $monitor->id }})" size="sm">{{ __('Save') }}</x-ui.button>
                                            <x-ui.button wire:click="cancelEditSelectors" variant="secondary" size="sm">{{ __('Cancel') }}</x-ui.button>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-2">{{ __('Fallback selectors (default, google, selector1, s1, k1, pm, etc.) are always tried automatically.') }}</p>
                                    </div>
                                @elseif(!empty($configuredSelectors))
                                    <div class="mt-2 text-[10px] text-gray-500">
                                        {{ __('Configured selectors') }}: <span class="font-mono">{{ implode(', ', $configuredSelectors) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @elseif($expandedMonitor === $monitor->id && empty($records))
                        <div class="border-t border-gray-100 dark:border-gray-700 px-4 py-6 text-center text-sm text-gray-400">
                            {{ __('No DNS data yet. Check will run shortly.') }}
                        </div>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <div class="py-12 text-center text-sm text-gray-500">{{ __('No DNS monitors configured.') }}</div>
                </x-ui.card>
            @endforelse
        </div>

        @if($monitors instanceof \Illuminate\Pagination\LengthAwarePaginator && $monitors->hasPages())
            <div class="mt-4">{{ $monitors->links() }}</div>
        @endif
    @else
        {{-- Changes tab --}}
        <x-ui.card class="!p-0 overflow-hidden">
            @forelse($changes as $change)
                <div class="flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <div class="h-7 w-7 rounded-full bg-accent-100 dark:bg-accent-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="h-3.5 w-3.5 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <x-ui.badge variant="purple">{{ $change->record_type }}</x-ui.badge>
                            @if($change->monitor?->site)
                                <a href="{{ $change->monitor->site->siteCloudflare ? route('sites.cloudflare', $change->monitor->site) : route('sites.overview', $change->monitor->site) }}" class="text-sm text-accent-600 hover:underline" wire:navigate>{{ $change->monitor->domain }}</a>
                            @else
                                <span class="text-sm text-gray-900 dark:text-white">{{ $change->monitor?->domain ?? '—' }}</span>
                            @endif
                            @if($change->acknowledged_at)
                                <span class="text-[10px] text-green-500">{{ __('Acknowledged') }}</span>
                            @endif
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-gray-400">{{ __('Before') }}:</span>
                                <span class="text-red-600 font-mono">{{ is_array($change->old_value) ? implode(', ', array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $change->old_value)) : ($change->old_value ?? '—') }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">{{ __('After') }}:</span>
                                <span class="text-green-600 font-mono">{{ is_array($change->new_value) ? implode(', ', array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $change->new_value)) : ($change->new_value ?? '—') }}</span>
                            </div>
                        </div>
                        <div class="mt-1 text-[11px] text-gray-400">{{ $change->detected_at->diffForHumans() }}</div>
                    </div>
                    @unless($change->acknowledged_at)
                        <button wire:click="acknowledge({{ $change->id }})" class="shrink-0 self-center text-xs text-gray-400 hover:text-green-600" title="{{ __('Acknowledge') }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    @endunless
                </div>
            @empty
                <div class="py-12 text-center text-sm text-gray-500">{{ __('No DNS updates detected yet.') }}</div>
            @endforelse
        </x-ui.card>
        @if($changes instanceof \Illuminate\Pagination\LengthAwarePaginator && $changes->hasPages())
            <div class="mt-4">{{ $changes->links() }}</div>
        @endif
    @endif
</div>
