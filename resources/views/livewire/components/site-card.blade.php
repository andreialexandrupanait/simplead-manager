@php
    $s = \App\Helpers\SiteStatusHelper::compute($site);

    $uptimeColor = $s['uptime']['color'];     $uptimeTip = $s['uptime']['tip'];
    $sslColor = $s['ssl']['color'];           $sslTip = $s['ssl']['tip'];
    $responseColor = $s['response']['color']; $responseTip = $s['response']['tip'];
    $perfColor = $s['performance']['color'];  $perfTip = $s['performance']['tip'];
    $linksColor = $s['links']['color'];       $linksTip = $s['links']['tip'];
    $domainColor = $s['domain']['color'];     $domainTip = $s['domain']['tip'];
    $pluginsColor = $s['plugins']['color'];   $pluginsTip = $s['plugins']['tip'];
    $usersColor = $s['users']['color'];       $usersTip = $s['users']['tip'];
    $wpConnColor = $s['wpConn']['color'];     $wpConnTip = $s['wpConn']['tip'];
    $backupColor = $s['backup']['color'];     $backupTip = $s['backup']['tip'];
    $wpVerColor = $s['wpVersion']['color'];   $wpVerTip = $s['wpVersion']['tip'];
    $reportsColor = $s['reports']['color'];   $reportsTip = $s['reports']['tip'];

    // Gradient from domain hash for screenshot placeholder
    $hash = abs(crc32($site->domain));
    $hue1 = $hash % 360;
    $hue2 = ($hue1 + 40) % 360;
    $gradient = "linear-gradient(135deg, hsl({$hue1}, 70%, 85%), hsl({$hue2}, 60%, 90%))";
@endphp

<div class="group relative cursor-pointer overflow-hidden rounded-xl bg-white ring-1 ring-gray-200 transition-all hover:ring-purple-500/50 hover:shadow-lg hover:shadow-purple-500/10"
     @if($hasRunningJobs) wire:poll.5s="checkJobProgress" @endif
     @click="window.location='{{ route('sites.overview', $site) }}'">

    {{-- Zone 1 — Thumbnail (Full Width) --}}
    <div class="relative overflow-hidden rounded-t-xl" style="aspect-ratio: 5/2; background: {{ $gradient }}">
        {{-- Fallback: domain initial when no screenshot and no favicon --}}
        @if(!$site->screenshot_url && !$site->favicon_url)
            <span class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-5xl font-bold text-white/60 z-10">
                {{ strtoupper(substr($site->domain, 0, 1)) }}
            </span>
        @endif

        {{-- Favicon fallback when no screenshot --}}
        @if(!$site->screenshot_url && $site->favicon_url)
            <img src="{{ $site->favicon_url }}"
                 alt=""
                 class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-24 w-24 object-contain z-10"
                 onerror="this.style.display='none'">
        @endif

        {{-- Screenshot --}}
        @if($site->screenshot_url)
            <img src="{{ $site->screenshot_url }}"
                 alt=""
                 class="absolute inset-0 h-full w-full object-cover object-top z-20"
                 onerror="this.style.display='none'">
        @endif
    </div>

    {{-- Zone 2 — Content --}}
    <div class="px-4 pt-3 pb-2">
        {{-- Site name --}}
        <h3 class="text-base font-semibold text-gray-900 group-hover:text-purple-600 transition-colors truncate">
            {{ $site->name }}
        </h3>

        {{-- Domain (no gap from name) --}}
        <p class="text-sm text-gray-600 truncate">{{ $site->domain }}</p>

        {{-- Metadata row: Time + Visit Link --}}
        <div class="flex items-center justify-between text-xs mt-2">
            <span class="text-gray-500">Added {{ $site->created_at->diffForHumans() }}</span>
            <a href="{{ $site->url }}" target="_blank" rel="noopener" @click.stop
               class="inline-flex items-center gap-1 text-purple-400 hover:text-purple-300 transition-colors">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
                Visit
            </a>
        </div>

        {{-- 12-icon status row --}}
        <div class="flex items-center justify-between pt-2 mt-2 border-t border-gray-100">
            {{-- Group 1: Monitoring (6 icons) --}}
            <div class="flex items-center gap-2">
                {{-- 1. Uptime --}}
                <x-ui.tooltip :text="$uptimeTip">
                    <svg class="h-4 w-4 {{ $uptimeColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </x-ui.tooltip>

                {{-- 2. SSL --}}
                <x-ui.tooltip :text="$sslTip">
                    <svg class="h-4 w-4 {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </x-ui.tooltip>

                {{-- 3. Response Time --}}
                <x-ui.tooltip :text="$responseTip">
                    <svg class="h-4 w-4 {{ $responseColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </x-ui.tooltip>

                {{-- 4. Performance --}}
                <x-ui.tooltip :text="$perfTip">
                    <svg class="h-4 w-4 {{ $perfColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </x-ui.tooltip>

                {{-- 5. Links --}}
                <x-ui.tooltip :text="$linksTip">
                    <svg class="h-4 w-4 {{ $linksColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                </x-ui.tooltip>

                {{-- 6. Domain --}}
                <x-ui.tooltip :text="$domainTip">
                    <svg class="h-4 w-4 {{ $domainColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </x-ui.tooltip>
            </div>

            {{-- Divider --}}
            <div class="h-4 w-px bg-gray-200"></div>

            {{-- Group 2: WordPress (3 icons) --}}
            <div class="flex items-center gap-2">
                {{-- 7. Plugins --}}
                <x-ui.tooltip :text="$pluginsTip">
                    <svg class="h-4 w-4 {{ $pluginsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                </x-ui.tooltip>

                {{-- 8. Users --}}
                <x-ui.tooltip :text="$usersTip">
                    <svg class="h-4 w-4 {{ $usersColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </x-ui.tooltip>

                {{-- 9. WordPress Connected --}}
                <x-ui.tooltip :text="$wpConnTip">
                    <svg class="h-4 w-4 {{ $wpConnColor }}" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.5 12c0-1.19.25-2.32.69-3.35l3.81 10.44A8.51 8.51 0 013.5 12zm8.5 8.5c-.83 0-1.64-.12-2.4-.34l2.55-7.41 2.61 7.15c.02.04.04.07.06.1-.89.32-1.84.5-2.82.5zm1.1-12.47c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.83 0-2.24-.11-2.24-.11-.46-.02-.51.68-.05.7 0 0 .43.06.89.08l1.32 3.61-1.85 5.56-3.08-9.17c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.16 0-.35 0-.55-.01A8.49 8.49 0 0112 3.5c2.13 0 4.07.78 5.56 2.07-.04 0-.07-.01-.11-.01-1.39 0-2.08 1.07-2.08 1.9 0 .7.38 1.29.78 2 .3.52.65 1.19.65 2.16 0 .67-.26 1.45-.6 2.53l-.79 2.63-2.86-8.75zM16.62 18.77l2.59-7.47c.48-1.21.64-2.17.64-3.03 0-.31-.02-.6-.06-.87A8.48 8.48 0 0120.5 12a8.51 8.51 0 01-3.88 6.77z"/></svg>
                </x-ui.tooltip>
            </div>

            {{-- Divider --}}
            <div class="h-4 w-px bg-gray-200"></div>

            {{-- Group 3: Infrastructure (3 icons) --}}
            <div class="flex items-center gap-2">
                {{-- 10. Backup --}}
                <x-ui.tooltip :text="$backupTip">
                    <svg class="h-4 w-4 {{ $backupColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </x-ui.tooltip>

                {{-- 11. WP Version --}}
                <x-ui.tooltip :text="$wpVerTip">
                    <svg class="h-4 w-4 {{ $wpVerColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </x-ui.tooltip>

                {{-- 12. Reports --}}
                <x-ui.tooltip :text="$reportsTip">
                    <svg class="h-4 w-4 {{ $reportsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </x-ui.tooltip>
            </div>
        </div>
    </div>

    {{-- Job Progress (compact for card view) --}}
    @if(!empty($trackedJobs))
        <div class="px-4 pb-2" @click.stop>
            <x-ui.job-progress job-key="uptime" :jobs="$trackedJobs" title="Checking uptime..." class="!mb-1" />
            <x-ui.job-progress job-key="backup" :jobs="$trackedJobs" title="Creating backup..." class="!mb-1" />
        </div>
    @endif

    {{-- Zone 3 — Footer (conditional) --}}
    @if($site->client || $site->notes)
        <div class="flex items-center gap-2 border-t border-gray-200 px-3 py-2">
            @if($site->client)
                <span class="truncate text-xs text-gray-600">{{ $site->client->name }}</span>
            @endif
            @if($site->notes)
                <span class="ml-auto flex-shrink-0 text-gray-400">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </span>
            @endif
        </div>
    @endif
</div>
