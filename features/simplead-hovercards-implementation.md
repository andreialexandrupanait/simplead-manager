# SimpleAd Manager - Hovercards Implementation Guide

This document contains the complete implementation of the hovercards system for the SimpleAd Manager dashboard, inspired by WPMUDEV Hub.

---

## Overview

The hovercards system provides quick access to information and actions directly from the sites list, without leaving the main page. On hover over status icons, users see a contextual card with:

- **Updates** - List of plugins/themes with available updates + Update button
- **Backups** - Storage used, last backup, schedule + Create Backup button
- **Analytics** - Visits and pageviews with period selector
- **Reports** - Next scheduled report and Send Now option
- **Uptime** - Status, response time, uptime % and incidents

---

## Architecture

\`\`\`
app/Livewire/Sites/Hovercards/
├── UpdatesHovercard.php
├── BackupsHovercard.php
├── AnalyticsHovercard.php
├── ReportsHovercard.php
└── UptimeHovercard.php

resources/views/
├── components/
│   ├── ui/
│   │   └── hovercard.blade.php
│   └── sites/
│       └── site-row.blade.php
└── livewire/sites/hovercards/
    ├── updates-hovercard.blade.php
    ├── backups-hovercard.blade.php
    ├── analytics-hovercard.blade.php
    ├── reports-hovercard.blade.php
    └── uptime-hovercard.blade.php
\`\`\`

---

## 1. Hovercard Base Component

Blade component for the hovercard container with positioning and animations.

### `resources/views/components/ui/hovercard.blade.php`

```blade
{{--
    Hovercard Component
    
    Usage:
    <x-ui.hovercard>
        <x-slot:trigger>
            <button>Hover me</button>
        </x-slot:trigger>
        
        <x-slot:content>
            <div>Card content here</div>
        </x-slot:content>
    </x-ui.hovercard>
    
    Props:
    - position: 'bottom' | 'top' | 'left' | 'right' (default: bottom)
    - align: 'start' | 'center' | 'end' (default: center)
    - delay: hover delay in ms (default: 200)
    - width: card width class (default: w-72)
--}}

@props([
    'position' => 'bottom',
    'align' => 'center',
    'delay' => 200,
    'width' => 'w-72',
])

@php
    // Position classes
    $positionClasses = match($position) {
        'top' => 'bottom-full mb-2',
        'bottom' => 'top-full mt-2',
        'left' => 'right-full mr-2 top-1/2 -translate-y-1/2',
        'right' => 'left-full ml-2 top-1/2 -translate-y-1/2',
        default => 'top-full mt-2',
    };
    
    // Alignment classes (for top/bottom positions)
    $alignClasses = match($align) {
        'start' => 'left-0',
        'end' => 'right-0',
        'center' => 'left-1/2 -translate-x-1/2',
        default => 'left-1/2 -translate-x-1/2',
    };
    
    // Arrow position
    $arrowClasses = match($position) {
        'top' => 'bottom-0 translate-y-full border-t-white border-l-transparent border-r-transparent border-b-transparent',
        'bottom' => 'top-0 -translate-y-full border-b-white border-l-transparent border-r-transparent border-t-transparent',
        'left' => 'right-0 translate-x-full top-1/2 -translate-y-1/2 border-l-white border-t-transparent border-b-transparent border-r-transparent',
        'right' => 'left-0 -translate-x-full top-1/2 -translate-y-1/2 border-r-white border-t-transparent border-b-transparent border-l-transparent',
        default => 'top-0 -translate-y-full border-b-white border-l-transparent border-r-transparent border-t-transparent',
    };
    
    $arrowAlign = match($align) {
        'start' => 'left-4',
        'end' => 'right-4',
        'center' => 'left-1/2 -translate-x-1/2',
        default => 'left-1/2 -translate-x-1/2',
    };
@endphp

<div 
    x-data="{ 
        open: false, 
        timeout: null,
        enter() {
            this.timeout = setTimeout(() => { this.open = true }, {{ $delay }});
        },
        leave() {
            clearTimeout(this.timeout);
            this.open = false;
        }
    }"
    @mouseenter="enter"
    @mouseleave="leave"
    {{ $attributes->merge(['class' => 'relative inline-flex']) }}
>
    {{-- Trigger Element --}}
    <div class="cursor-pointer">
        {{ $trigger }}
    </div>
    
    {{-- Hovercard Content --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute z-50 {{ $positionClasses }} {{ $position === 'top' || $position === 'bottom' ? $alignClasses : '' }}"
        @mouseenter="open = true"
        @mouseleave="leave"
    >
        {{-- Card --}}
        <div class="{{ $width }} rounded-lg bg-white shadow-xl ring-1 ring-black/5 overflow-hidden">
            {{-- Arrow --}}
            <div class="absolute {{ $arrowClasses }} {{ $position === 'top' || $position === 'bottom' ? $arrowAlign : '' }} w-0 h-0 border-[6px]"></div>
            
            {{-- Content --}}
            {{ $content }}
        </div>
    </div>
</div>
```

---

## 2. Updates Hovercard

Displays available updates for WordPress core, plugins, and themes.

### `app/Livewire/Sites/Hovercards/UpdatesHovercard.php`

```php
<?php

namespace App\Livewire\Sites\Hovercards;

use App\Models\Site;
use Livewire\Component;

class UpdatesHovercard extends Component
{
    public Site $site;
    public bool $loaded = false;
    
    public array $updates = [];
    public int $totalCount = 0;
    public array $categoryCounts = [];
    
    public function mount(Site $site): void
    {
        $this->site = $site;
    }
    
    /**
     * Load updates data when the hovercard becomes visible
     * Called via Alpine.js x-intersect or manually
     */
    public function loadData(): void
    {
        if ($this->loaded) {
            return;
        }
        
        // Fetch updates from the site's WordPress connector
        $wpData = $this->site->getWordPressData();
        
        $this->updates = $wpData['updates'] ?? [];
        
        // Calculate counts by category
        $this->categoryCounts = [
            'core' => collect($this->updates)->where('type', 'core')->count(),
            'plugins' => collect($this->updates)->where('type', 'plugin')->count(),
            'themes' => collect($this->updates)->where('type', 'theme')->count(),
        ];
        
        $this->totalCount = count($this->updates);
        $this->loaded = true;
    }
    
    /**
     * Update a single item (plugin/theme/core)
     */
    public function updateItem(string $type, string $slug): void
    {
        $this->dispatch('site-update-started', [
            'siteId' => $this->site->id,
            'type' => $type,
            'slug' => $slug,
        ]);
        
        // Queue the update job
        // UpdateWordPressItem::dispatch($this->site, $type, $slug);
        
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Update started for ' . $slug,
        ]);
    }
    
    /**
     * Update all items at once
     */
    public function updateAll(): void
    {
        $this->dispatch('site-update-all-started', [
            'siteId' => $this->site->id,
        ]);
        
        // Queue update all job
        // UpdateAllWordPressItems::dispatch($this->site);
        
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Updating all items on ' . $this->site->name,
        ]);
    }
    
    public function render()
    {
        return view('livewire.sites.hovercards.updates-hovercard');
    }
}
```

### `resources/views/livewire/sites/hovercards/updates-hovercard.blade.php`

```blade
{{--
    Updates Hovercard View
    
    Displays available updates for WordPress core, plugins, and themes.
    Matches WPMUDEV Hub design with icons, list items, and action buttons.
--}}

<div 
    x-data="{ loaded: @entangle('loaded') }"
    x-init="$wire.loadData()"
    class="min-w-[280px]"
>
    {{-- Header with tabs --}}
    <div class="border-b border-gray-100">
        {{-- Icon tabs row --}}
        <div class="flex items-center gap-1 px-3 pt-3 pb-2">
            {{-- Update count badge --}}
            <div class="flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-amber-700 mr-2">
                <span class="text-sm font-semibold">{{ $totalCount }}</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>
            
            {{-- Separator --}}
            <div class="h-4 w-px bg-gray-200"></div>
            
            {{-- Category icons --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors {{ $categoryCounts['core'] > 0 ? 'text-amber-600' : 'text-gray-300' }}" title="WordPress Core">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 19.5c-5.247 0-9.5-4.253-9.5-9.5S6.753 2.5 12 2.5s9.5 4.253 9.5 9.5-4.253 9.5-9.5 9.5z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors {{ $categoryCounts['plugins'] > 0 ? 'text-amber-600' : 'text-gray-300' }}" title="Plugins">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M13.5 2c-.828 0-1.5.672-1.5 1.5V5H8V3.5C8 2.672 7.328 2 6.5 2S5 2.672 5 3.5V5H3.5A1.5 1.5 0 002 6.5v3A1.5 1.5 0 003.5 11H5v2H3.5A1.5 1.5 0 002 14.5v3A1.5 1.5 0 003.5 19H5v1.5c0 .828.672 1.5 1.5 1.5s1.5-.672 1.5-1.5V19h4v1.5c0 .828.672 1.5 1.5 1.5s1.5-.672 1.5-1.5V19h1.5a1.5 1.5 0 001.5-1.5v-3a1.5 1.5 0 00-1.5-1.5H15v-2h1.5a1.5 1.5 0 001.5-1.5v-3A1.5 1.5 0 0016.5 5H15V3.5c0-.828-.672-1.5-1.5-1.5z"/>
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors {{ $categoryCounts['themes'] > 0 ? 'text-amber-600' : 'text-gray-300' }}" title="Themes">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3z"/>
                </svg>
            </button>
            
            {{-- WooCommerce icon (if applicable) --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="WooCommerce">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2.5 4.5C2.5 3.4 3.4 2.5 4.5 2.5h15c1.1 0 2 .9 2 2v11c0 1.1-.9 2-2 2h-6l-4 4v-4h-5c-1.1 0-2-.9-2-2v-11z"/>
                </svg>
            </button>
        </div>
        
        {{-- Title row --}}
        <div class="flex items-center justify-between px-4 py-2">
            <h3 class="text-sm font-semibold text-gray-900">New Updates</h3>
            @if($totalCount > 0)
                <button 
                    wire:click="updateAll"
                    wire:loading.attr="disabled"
                    class="text-xs font-medium text-purple-600 hover:text-purple-700 transition-colors disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="updateAll">Update all</span>
                    <span wire:loading wire:target="updateAll">Updating...</span>
                </button>
            @endif
        </div>
    </div>
    
    {{-- Content --}}
    <div class="max-h-64 overflow-y-auto">
        @if(!$loaded)
            {{-- Loading skeleton --}}
            <div class="p-4 space-y-3">
                @for($i = 0; $i < 3; $i++)
                    <div class="flex items-center gap-3 animate-pulse">
                        <div class="h-6 w-6 rounded bg-gray-200"></div>
                        <div class="flex-1 h-4 rounded bg-gray-200"></div>
                        <div class="h-4 w-12 rounded bg-gray-200"></div>
                    </div>
                @endfor
            </div>
        @elseif($totalCount === 0)
            {{-- Empty state --}}
            <div class="p-6 text-center">
                <div class="mx-auto h-10 w-10 rounded-full bg-green-100 flex items-center justify-center mb-2">
                    <svg class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <p class="text-sm text-gray-500">All up to date!</p>
            </div>
        @else
            {{-- Updates list --}}
            <div class="divide-y divide-gray-50">
                {{-- Core updates --}}
                @if($categoryCounts['core'] > 0)
                    <div class="px-4 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Core</p>
                        @foreach(collect($updates)->where('type', 'core') as $update)
                            <div class="flex items-center justify-between py-1.5 group">
                                <div class="flex items-center gap-2.5">
                                    <div class="h-6 w-6 rounded bg-blue-100 flex items-center justify-center">
                                        <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2z"/>
                                        </svg>
                                    </div>
                                    <span class="text-sm text-gray-700">WordPress</span>
                                    <span class="text-xs text-gray-400">{{ $update['current'] ?? '' }} → {{ $update['new'] ?? '' }}</span>
                                </div>
                                <button 
                                    wire:click="updateItem('core', 'wordpress')"
                                    class="text-xs font-medium text-purple-600 hover:text-purple-700 opacity-0 group-hover:opacity-100 transition-all"
                                >
                                    Update
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
                
                {{-- Plugin updates --}}
                @if($categoryCounts['plugins'] > 0)
                    <div class="px-4 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Plugins</p>
                        @foreach(collect($updates)->where('type', 'plugin')->take(5) as $update)
                            <div class="flex items-center justify-between py-1.5 group">
                                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                    @if(!empty($update['icon']))
                                        <img src="{{ $update['icon'] }}" alt="" class="h-6 w-6 rounded">
                                    @else
                                        <div class="h-6 w-6 rounded bg-gray-100 flex items-center justify-center">
                                            <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M13.5 2c-.828 0-1.5.672-1.5 1.5V5H8V3.5C8 2.672 7.328 2 6.5 2S5 2.672 5 3.5V5H3.5A1.5 1.5 0 002 6.5v3A1.5 1.5 0 003.5 11H5v2H3.5A1.5 1.5 0 002 14.5v3A1.5 1.5 0 003.5 19H5v1.5c0 .828.672 1.5 1.5 1.5s1.5-.672 1.5-1.5V19h4v1.5c0 .828.672 1.5 1.5 1.5s1.5-.672 1.5-1.5V19h1.5a1.5 1.5 0 001.5-1.5v-3a1.5 1.5 0 00-1.5-1.5H15v-2h1.5a1.5 1.5 0 001.5-1.5v-3A1.5 1.5 0 0016.5 5H15V3.5c0-.828-.672-1.5-1.5-1.5z"/>
                                            </svg>
                                        </div>
                                    @endif
                                    <span class="text-sm text-gray-700 truncate">{{ $update['name'] ?? $update['slug'] }}</span>
                                </div>
                                <button 
                                    wire:click="updateItem('plugin', '{{ $update['slug'] }}')"
                                    class="text-xs font-medium text-purple-600 hover:text-purple-700 opacity-0 group-hover:opacity-100 transition-all ml-2 shrink-0"
                                >
                                    Update
                                </button>
                            </div>
                        @endforeach
                        
                        @if($categoryCounts['plugins'] > 5)
                            <a href="{{ route('sites.plugins', $site) }}" wire:navigate class="block text-xs text-gray-400 hover:text-gray-600 mt-1">
                                +{{ $categoryCounts['plugins'] - 5 }} more
                            </a>
                        @endif
                    </div>
                @endif
                
                {{-- Theme updates --}}
                @if($categoryCounts['themes'] > 0)
                    <div class="px-4 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Themes</p>
                        @foreach(collect($updates)->where('type', 'theme')->take(3) as $update)
                            <div class="flex items-center justify-between py-1.5 group">
                                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                    @if(!empty($update['icon']))
                                        <img src="{{ $update['icon'] }}" alt="" class="h-6 w-6 rounded">
                                    @else
                                        <div class="h-6 w-6 rounded bg-gray-100 flex items-center justify-center">
                                            <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3z"/>
                                            </svg>
                                        </div>
                                    @endif
                                    <span class="text-sm text-gray-700 truncate">{{ $update['name'] ?? $update['slug'] }}</span>
                                </div>
                                <button 
                                    wire:click="updateItem('theme', '{{ $update['slug'] }}')"
                                    class="text-xs font-medium text-purple-600 hover:text-purple-700 opacity-0 group-hover:opacity-100 transition-all ml-2 shrink-0"
                                >
                                    Update
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
```

---

## 3. Backups Hovercard

Displays backup information and allows quick backup creation.

### `app/Livewire/Sites/Hovercards/BackupsHovercard.php`

```php
<?php

namespace App\Livewire\Sites\Hovercards;

use App\Models\Site;
use App\Models\Backup;
use App\Jobs\CreateSiteBackup;
use Livewire\Component;

class BackupsHovercard extends Component
{
    public Site $site;
    public bool $loaded = false;
    
    public ?Backup $lastBackup = null;
    public int $storageUsed = 0;
    public int $storageAvailable = 0;
    public ?string $schedule = null;
    public int $totalBackups = 0;
    
    public function mount(Site $site): void
    {
        $this->site = $site;
    }
    
    /**
     * Load backup data when hovercard becomes visible
     */
    public function loadData(): void
    {
        if ($this->loaded) {
            return;
        }
        
        // Get last backup
        $this->lastBackup = $this->site->backups()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        
        // Calculate storage used by this site's backups
        $this->storageUsed = $this->site->backups()
            ->where('status', 'completed')
            ->sum('size');
        
        // Get available storage from Dropbox or configured storage
        $this->storageAvailable = $this->getAvailableStorage();
        
        // Get backup schedule
        $this->schedule = $this->site->backup_schedule;
        
        // Total backup count
        $this->totalBackups = $this->site->backups()
            ->where('status', 'completed')
            ->count();
        
        $this->loaded = true;
    }
    
    /**
     * Get available storage from configured backup destination
     */
    protected function getAvailableStorage(): int
    {
        // This would query your Dropbox integration or other storage provider
        // For now returning a placeholder
        return $this->site->backupDestination?->available_space ?? 0;
    }
    
    /**
     * Create a new backup
     */
    public function createBackup(): void
    {
        // Dispatch backup job
        CreateSiteBackup::dispatch($this->site);
        
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Backup started for ' . $this->site->name,
        ]);
        
        $this->dispatch('backup-started', siteId: $this->site->id);
    }
    
    /**
     * Format bytes to human readable
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 KB';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        
        return round($bytes / pow(1024, $pow), 1) . ' ' . $units[$pow];
    }
    
    public function render()
    {
        return view('livewire.sites.hovercards.backups-hovercard');
    }
}
```

### `resources/views/livewire/sites/hovercards/backups-hovercard.blade.php`

```blade
{{--
    Backups Hovercard View
    
    Displays backup status, storage usage, and quick backup action.
    Matches WPMUDEV Hub design.
--}}

<div 
    x-data="{ loaded: @entangle('loaded') }"
    x-init="$wire.loadData()"
    class="min-w-[260px]"
>
    {{-- Header with icon tabs --}}
    <div class="border-b border-gray-100">
        <div class="flex items-center gap-1 px-3 pt-3 pb-2">
            {{-- Backup icon (active) --}}
            <button class="p-1.5 rounded bg-purple-50 text-purple-600" title="Backups">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                </svg>
            </button>
            
            {{-- Other feature icons (inactive placeholders) --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Security">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Performance">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="SEO">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>
        </div>
        
        <div class="px-4 py-2">
            <h3 class="text-sm font-semibold text-gray-900">Backups</h3>
        </div>
    </div>
    
    {{-- Content --}}
    <div class="p-4">
        @if(!$loaded)
            {{-- Loading skeleton --}}
            <div class="space-y-4 animate-pulse">
                <div class="flex justify-between">
                    <div class="h-4 w-24 bg-gray-200 rounded"></div>
                    <div class="h-4 w-16 bg-gray-200 rounded"></div>
                </div>
                <div class="h-2 w-full bg-gray-200 rounded"></div>
                <div class="flex justify-between">
                    <div class="h-4 w-20 bg-gray-200 rounded"></div>
                    <div class="h-4 w-24 bg-gray-200 rounded"></div>
                </div>
            </div>
        @else
            <div class="space-y-4">
                {{-- Storage Used --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Storage Used</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $this->formatBytes($storageUsed) }}</span>
                </div>
                
                {{-- Storage Available with progress bar --}}
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Storage Available</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $this->formatBytes($storageAvailable) }}</span>
                    </div>
                    @php
                        $usagePercent = $storageAvailable > 0 
                            ? min(100, ($storageUsed / ($storageUsed + $storageAvailable)) * 100) 
                            : 0;
                    @endphp
                    <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                        <div 
                            class="h-full rounded-full transition-all duration-500 {{ $usagePercent > 80 ? 'bg-red-500' : ($usagePercent > 60 ? 'bg-amber-500' : 'bg-blue-500') }}"
                            style="width: {{ $usagePercent }}%"
                        ></div>
                    </div>
                </div>
                
                {{-- Last Backup --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Last Backup</span>
                    <span class="text-sm font-semibold text-gray-900">
                        @if($lastBackup)
                            {{ $lastBackup->completed_at->diffForHumans() }}
                        @else
                            <span class="text-gray-400">Never</span>
                        @endif
                    </span>
                </div>
                
                {{-- Schedule --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Schedule</span>
                    <span class="text-sm font-semibold text-gray-900">
                        @if($schedule)
                            {{ ucfirst($schedule) }}
                        @else
                            <span class="text-gray-400">None</span>
                        @endif
                    </span>
                </div>
                
                {{-- Create Backup Button --}}
                <button 
                    wire:click="createBackup"
                    wire:loading.attr="disabled"
                    class="w-full mt-2 inline-flex items-center justify-center gap-2 rounded-lg border border-purple-200 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span wire:loading.remove wire:target="createBackup">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </span>
                    <span wire:loading wire:target="createBackup">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </span>
                    <span wire:loading.remove wire:target="createBackup">Create Backup</span>
                    <span wire:loading wire:target="createBackup">Creating...</span>
                </button>
            </div>
        @endif
    </div>
</div>
```

---

## 4. Analytics Hovercard

Displays visits and pageviews statistics with period selector.

### `app/Livewire/Sites/Hovercards/AnalyticsHovercard.php`

```php
<?php

namespace App\Livewire\Sites\Hovercards;

use App\Models\Site;
use Livewire\Component;
use Carbon\Carbon;

class AnalyticsHovercard extends Component
{
    public Site $site;
    public bool $loaded = false;
    
    public string $period = '7d';
    public int $visits = 0;
    public int $pageviews = 0;
    public float $visitsChange = 0;
    public float $pageviewsChange = 0;
    
    protected $queryString = [];
    
    public function mount(Site $site): void
    {
        $this->site = $site;
    }
    
    /**
     * Load analytics data when hovercard becomes visible
     */
    public function loadData(): void
    {
        if ($this->loaded) {
            return;
        }
        
        $this->fetchAnalytics();
        $this->loaded = true;
    }
    
    /**
     * Change the time period and refresh data
     */
    public function setPeriod(string $period): void
    {
        $this->period = $period;
        $this->fetchAnalytics();
    }
    
    /**
     * Fetch analytics data from the site's analytics integration
     */
    protected function fetchAnalytics(): void
    {
        $days = match($this->period) {
            '24h' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };
        
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);
        $previousStart = $startDate->copy()->subDays($days);
        $previousEnd = $startDate->copy();
        
        // Current period data
        $currentData = $this->site->getAnalyticsData($startDate, $endDate);
        $this->visits = $currentData['visits'] ?? 0;
        $this->pageviews = $currentData['pageviews'] ?? 0;
        
        // Previous period for comparison
        $previousData = $this->site->getAnalyticsData($previousStart, $previousEnd);
        $previousVisits = $previousData['visits'] ?? 0;
        $previousPageviews = $previousData['pageviews'] ?? 0;
        
        // Calculate percentage changes
        $this->visitsChange = $previousVisits > 0 
            ? round((($this->visits - $previousVisits) / $previousVisits) * 100, 1)
            : 0;
            
        $this->pageviewsChange = $previousPageviews > 0 
            ? round((($this->pageviews - $previousPageviews) / $previousPageviews) * 100, 1)
            : 0;
    }
    
    /**
     * Get period options for dropdown
     */
    public function getPeriodOptionsProperty(): array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
        ];
    }
    
    public function render()
    {
        return view('livewire.sites.hovercards.analytics-hovercard');
    }
}
```

### `resources/views/livewire/sites/hovercards/analytics-hovercard.blade.php`

```blade
{{--
    Analytics Hovercard View
    
    Displays visits and pageviews with period selector.
    Matches WPMUDEV Hub design.
--}}

<div 
    x-data="{ loaded: @entangle('loaded'), periodOpen: false }"
    x-init="$wire.loadData()"
    class="min-w-[240px]"
>
    {{-- Header with icon tabs --}}
    <div class="border-b border-gray-100">
        <div class="flex items-center gap-1 px-3 pt-3 pb-2">
            {{-- Analytics icon (active) --}}
            <button class="p-1.5 rounded bg-green-50 text-green-600" title="Analytics">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </button>
            
            {{-- Other icons --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Files">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Users">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </button>
            
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="WordPress">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 19c-4.963 0-9-4.037-9-9s4.037-9 9-9 9 4.037 9 9-4.037 9-9 9z"/>
                </svg>
            </button>
        </div>
        
        {{-- Title with period selector --}}
        <div class="flex items-center justify-between px-4 py-2">
            <h3 class="text-sm font-semibold text-gray-900">Analytics</h3>
            
            {{-- Period dropdown --}}
            <div class="relative" x-data="{ open: false }">
                <button 
                    @click="open = !open"
                    class="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700 transition-colors"
                >
                    <span>{{ $this->periodOptions[$period] ?? 'Last 7 days' }}</span>
                    <svg class="h-3 w-3 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                
                <div 
                    x-show="open" 
                    @click.away="open = false"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="transform opacity-0 scale-95"
                    x-transition:enter-end="transform opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="transform opacity-100 scale-100"
                    x-transition:leave-end="transform opacity-0 scale-95"
                    class="absolute right-0 mt-1 w-32 rounded-md bg-white shadow-lg ring-1 ring-black/5 z-10"
                >
                    <div class="py-1">
                        @foreach($this->periodOptions as $value => $label)
                            <button 
                                wire:click="setPeriod('{{ $value }}')"
                                @click="open = false"
                                class="block w-full text-left px-3 py-1.5 text-xs {{ $period === $value ? 'text-purple-600 bg-purple-50' : 'text-gray-700 hover:bg-gray-50' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Content --}}
    <div class="p-4">
        @if(!$loaded)
            {{-- Loading skeleton --}}
            <div class="space-y-4 animate-pulse">
                <div class="flex justify-between">
                    <div class="h-4 w-16 bg-gray-200 rounded"></div>
                    <div class="h-5 w-12 bg-gray-200 rounded"></div>
                </div>
                <div class="flex justify-between">
                    <div class="h-4 w-20 bg-gray-200 rounded"></div>
                    <div class="h-5 w-12 bg-gray-200 rounded"></div>
                </div>
            </div>
        @else
            <div class="space-y-4">
                {{-- Visits --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Visits</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900">{{ number_format($visits) }}</span>
                        @if($visitsChange != 0)
                            <span class="inline-flex items-center text-xs font-medium {{ $visitsChange > 0 ? 'text-green-600' : 'text-red-600' }}">
                                @if($visitsChange > 0)
                                    <svg class="h-3 w-3 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                    </svg>
                                @else
                                    <svg class="h-3 w-3 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                    </svg>
                                @endif
                                {{ abs($visitsChange) }}%
                            </span>
                        @endif
                    </div>
                </div>
                
                {{-- Pageviews --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Pageviews</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900">{{ number_format($pageviews) }}</span>
                        @if($pageviewsChange != 0)
                            <span class="inline-flex items-center text-xs font-medium {{ $pageviewsChange > 0 ? 'text-green-600' : 'text-red-600' }}">
                                @if($pageviewsChange > 0)
                                    <svg class="h-3 w-3 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                    </svg>
                                @else
                                    <svg class="h-3 w-3 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                    </svg>
                                @endif
                                {{ abs($pageviewsChange) }}%
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            
            {{-- View full analytics link --}}
            <a 
                href="{{ route('sites.analytics', $site) }}" 
                wire:navigate
                class="mt-4 block text-center text-xs text-purple-600 hover:text-purple-700 font-medium"
            >
                View full analytics →
            </a>
        @endif
    </div>
</div>
```

---

## 5. Reports Hovercard

Displays scheduled reports information.

### `app/Livewire/Sites/Hovercards/ReportsHovercard.php`

```php
<?php

namespace App\Livewire\Sites\Hovercards;

use App\Models\Site;
use App\Models\Report;
use Livewire\Component;

class ReportsHovercard extends Component
{
    public Site $site;
    public bool $loaded = false;
    
    public ?Report $nextReport = null;
    public ?Report $lastReport = null;
    public int $totalReports = 0;
    
    public function mount(Site $site): void
    {
        $this->site = $site;
    }
    
    /**
     * Load reports data when hovercard becomes visible
     */
    public function loadData(): void
    {
        if ($this->loaded) {
            return;
        }
        
        // Get next scheduled report
        $this->nextReport = $this->site->reports()
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at', 'asc')
            ->first();
        
        // Get last completed report
        $this->lastReport = $this->site->reports()
            ->where('status', 'sent')
            ->latest('sent_at')
            ->first();
        
        // Total reports count
        $this->totalReports = $this->site->reports()
            ->where('status', 'sent')
            ->count();
        
        $this->loaded = true;
    }
    
    /**
     * Send report now (manual trigger)
     */
    public function sendNow(): void
    {
        if (!$this->nextReport) {
            return;
        }
        
        // Dispatch job to generate and send report
        // GenerateReport::dispatch($this->site, $this->nextReport);
        
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Report generation started',
        ]);
    }
    
    public function render()
    {
        return view('livewire.sites.hovercards.reports-hovercard');
    }
}
```

### `resources/views/livewire/sites/hovercards/reports-hovercard.blade.php`

```blade
{{--
    Reports Hovercard View
    
    Displays scheduled reports and next report info.
    Matches WPMUDEV Hub design.
--}}

<div 
    x-data="{ loaded: @entangle('loaded') }"
    x-init="$wire.loadData()"
    class="min-w-[240px]"
>
    {{-- Header with icon tabs --}}
    <div class="border-b border-gray-100">
        <div class="flex items-center gap-1 px-3 pt-3 pb-2">
            {{-- Report icon (inactive) --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Files">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
            </button>
            
            {{-- Users icon --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="Users">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </button>
            
            {{-- WordPress icon --}}
            <button class="p-1.5 rounded hover:bg-gray-100 transition-colors text-gray-300" title="WordPress">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 19c-4.963 0-9-4.037-9-9s4.037-9 9-9 9 4.037 9 9-4.037 9-9 9z"/>
                </svg>
            </button>
            
            {{-- Reports icon (active) --}}
            <button class="p-1.5 rounded bg-orange-50 text-orange-600" title="Reports">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </button>
        </div>
        
        <div class="px-4 py-2">
            <h3 class="text-sm font-semibold text-gray-900">Reports</h3>
        </div>
    </div>
    
    {{-- Content --}}
    <div class="p-4">
        @if(!$loaded)
            {{-- Loading skeleton --}}
            <div class="space-y-4 animate-pulse">
                <div class="flex justify-between">
                    <div class="h-4 w-16 bg-gray-200 rounded"></div>
                    <div class="h-4 w-32 bg-gray-200 rounded"></div>
                </div>
                <div class="flex justify-between">
                    <div class="h-4 w-12 bg-gray-200 rounded"></div>
                    <div class="h-4 w-28 bg-gray-200 rounded"></div>
                </div>
            </div>
        @else
            @if(!$nextReport && !$lastReport)
                {{-- No reports configured --}}
                <div class="text-center py-4">
                    <div class="mx-auto h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center mb-2">
                        <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <p class="text-sm text-gray-500 mb-3">No reports configured</p>
                    <a 
                        href="{{ route('sites.reports.create', $site) }}" 
                        wire:navigate
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-purple-600 hover:text-purple-700"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Create Report
                    </a>
                </div>
            @else
                <div class="space-y-4">
                    {{-- Report Name --}}
                    @if($nextReport)
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Name</span>
                            <span class="text-sm font-medium text-gray-900 truncate max-w-[140px]" title="{{ $nextReport->name }}">
                                {{ $nextReport->name }}
                            </span>
                        </div>
                    @endif
                    
                    {{-- Next Report Date --}}
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Next</span>
                        @if($nextReport)
                            <span class="text-sm font-semibold text-gray-900">
                                {{ $nextReport->scheduled_at->format('M j, g:ia') }}
                            </span>
                        @else
                            <span class="text-sm text-gray-400">Not scheduled</span>
                        @endif
                    </div>
                    
                    {{-- Last Sent --}}
                    @if($lastReport)
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Last Sent</span>
                            <span class="text-sm text-gray-600">
                                {{ $lastReport->sent_at->diffForHumans() }}
                            </span>
                        </div>
                    @endif
                </div>
                
                {{-- Actions --}}
                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                    <a 
                        href="{{ route('sites.reports', $site) }}" 
                        wire:navigate
                        class="text-xs text-gray-500 hover:text-gray-700"
                    >
                        View all reports
                    </a>
                    
                    @if($nextReport)
                        <button 
                            wire:click="sendNow"
                            wire:loading.attr="disabled"
                            class="text-xs font-medium text-purple-600 hover:text-purple-700 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="sendNow">Send now</span>
                            <span wire:loading wire:target="sendNow">Sending...</span>
                        </button>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
```

---

## 6. Uptime Hovercard

Displays uptime status, response time, and recent incidents.

### `app/Livewire/Sites/Hovercards/UptimeHovercard.php`

```php
<?php

namespace App\Livewire\Sites\Hovercards;

use App\Models\Site;
use Livewire\Component;

class UptimeHovercard extends Component
{
    public Site $site;
    public bool $loaded = false;
    
    public string $status = 'unknown';
    public ?string $lastChecked = null;
    public float $uptimePercent = 0;
    public int $responseTime = 0;
    public int $incidentsCount = 0;
    public ?string $lastIncident = null;
    
    public function mount(Site $site): void
    {
        $this->site = $site;
    }
    
    /**
     * Load uptime data when hovercard becomes visible
     */
    public function loadData(): void
    {
        if ($this->loaded) {
            return;
        }
        
        $monitor = $this->site->uptimeMonitor;
        
        if ($monitor) {
            $this->status = $monitor->status;
            $this->lastChecked = $monitor->last_checked_at?->diffForHumans();
            $this->uptimePercent = $monitor->uptime_percentage_30d ?? 0;
            $this->responseTime = $monitor->avg_response_time ?? 0;
            
            // Get incidents from last 30 days
            $this->incidentsCount = $monitor->incidents()
                ->where('started_at', '>=', now()->subDays(30))
                ->count();
            
            $lastIncident = $monitor->incidents()->latest('started_at')->first();
            $this->lastIncident = $lastIncident?->started_at?->diffForHumans();
        }
        
        $this->loaded = true;
    }
    
    /**
     * Manually trigger an uptime check
     */
    public function checkNow(): void
    {
        // Dispatch immediate check job
        // CheckUptimeNow::dispatch($this->site->uptimeMonitor);
        
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Checking uptime for ' . $this->site->name,
        ]);
    }
    
    public function render()
    {
        return view('livewire.sites.hovercards.uptime-hovercard');
    }
}
```

### `resources/views/livewire/sites/hovercards/uptime-hovercard.blade.php`

```blade
{{--
    Uptime Hovercard View
--}}

<div 
    x-data="{ loaded: @entangle('loaded') }"
    x-init="$wire.loadData()"
    class="min-w-[240px]"
>
    {{-- Header --}}
    <div class="border-b border-gray-100">
        <div class="flex items-center gap-1 px-3 pt-3 pb-2">
            <div class="flex items-center gap-2 px-2 py-1 rounded-md {{ $status === 'up' ? 'bg-green-50' : ($status === 'down' ? 'bg-red-50' : 'bg-gray-50') }}">
                <span class="relative flex h-2 w-2">
                    @if($status === 'up')
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    @elseif($status === 'down')
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    @else
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
                    @endif
                </span>
                <span class="text-xs font-medium {{ $status === 'up' ? 'text-green-700' : ($status === 'down' ? 'text-red-700' : 'text-gray-600') }}">
                    {{ ucfirst($status) }}
                </span>
            </div>
        </div>
        
        <div class="px-4 py-2">
            <h3 class="text-sm font-semibold text-gray-900">Uptime Monitor</h3>
        </div>
    </div>
    
    {{-- Content --}}
    <div class="p-4">
        @if(!$loaded)
            <div class="space-y-4 animate-pulse">
                <div class="flex justify-between">
                    <div class="h-4 w-20 bg-gray-200 rounded"></div>
                    <div class="h-4 w-16 bg-gray-200 rounded"></div>
                </div>
            </div>
        @else
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Uptime (30d)</span>
                    <span class="text-sm font-semibold {{ $uptimePercent >= 99.5 ? 'text-green-600' : ($uptimePercent >= 99 ? 'text-amber-600' : 'text-red-600') }}">
                        {{ number_format($uptimePercent, 2) }}%
                    </span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Avg Response</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $responseTime }}ms</span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Last Check</span>
                    <span class="text-sm text-gray-600">{{ $lastChecked ?? 'Never' }}</span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Incidents (30d)</span>
                    <span class="text-sm font-semibold {{ $incidentsCount === 0 ? 'text-green-600' : 'text-red-600' }}">{{ $incidentsCount }}</span>
                </div>
                
                @if($lastIncident)
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Last Incident</span>
                        <span class="text-sm text-gray-600">{{ $lastIncident }}</span>
                    </div>
                @endif
            </div>
            
            <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                <a href="{{ route('sites.uptime', $site) }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-700">View details</a>
                <button wire:click="checkNow" wire:loading.attr="disabled" class="text-xs font-medium text-purple-600 hover:text-purple-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="checkNow">Check now</span>
                    <span wire:loading wire:target="checkNow">Checking...</span>
                </button>
            </div>
        @endif
    </div>
</div>
```

---

## 7. Site Row Component (Integration)

Component that integrates all hovercards into the sites list.

### `resources/views/components/sites/site-row.blade.php`

```blade
{{--
    Site Row Component
    
    A row in the sites list with hovercards on status icons.
    Replicates WPMUDEV Hub's site list design.
    
    Usage:
    <x-sites.site-row :site="$site" />
--}}

@props(['site'])

@php
    // Determine status colors for the health badge
    $healthColor = match(true) {
        $site->health_score >= 90 => 'bg-green-500',
        $site->health_score >= 70 => 'bg-yellow-500',
        $site->health_score >= 50 => 'bg-orange-500',
        default => 'bg-red-500',
    };
    
    // Check various statuses
    $hasUpdates = $site->pending_updates_count > 0;
    $isUp = $site->uptime_status === 'up';
    $sslValid = $site->ssl_valid;
    $hasBackups = $site->last_backup_at !== null;
    $hasAnalytics = $site->analytics_connected;
    $hasReports = $site->reports_count > 0;
@endphp

<div 
    class="group flex items-center gap-4 rounded-lg border border-gray-100 bg-white px-4 py-3 transition-all hover:border-gray-200 hover:shadow-sm"
>
    {{-- Health Score Badge --}}
    <div class="flex-shrink-0">
        <div class="relative h-9 w-9 rounded-full {{ $healthColor }} flex items-center justify-center">
            <span class="text-xs font-bold text-white">{{ $site->health_score ?? 0 }}</span>
        </div>
    </div>
    
    {{-- Site Name & Domain --}}
    <div class="min-w-0 flex-1">
        <a 
            href="{{ route('sites.overview', $site) }}" 
            wire:navigate
            class="block truncate text-sm font-medium text-gray-900 hover:text-purple-600 transition-colors"
        >
            {{ $site->name }}
        </a>
        <p class="truncate text-xs text-gray-500">{{ $site->domain }}</p>
    </div>
    
    {{-- Updates Count --}}
    <div class="flex items-center gap-1 text-xs {{ $hasUpdates ? 'text-amber-600' : 'text-gray-400' }}">
        <span class="font-medium">{{ $site->pending_updates_count ?? 0 }}</span>
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
    </div>
    
    {{-- Separator --}}
    <div class="h-4 w-px bg-gray-200"></div>
    
    {{-- Status Icons with Hovercards --}}
    <div class="flex items-center gap-1">
        
        {{-- Uptime Status --}}
        <x-ui.hovercard position="bottom" align="center" :delay="150" width="w-64">
            <x-slot:trigger>
                <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $isUp ? 'text-green-500' : 'text-red-500' }}" title="Uptime">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" />
                    </svg>
                </div>
            </x-slot:trigger>
            <x-slot:content>
                <livewire:sites.hovercards.uptime-hovercard :site="$site" :key="'uptime-'.$site->id" />
            </x-slot:content>
        </x-ui.hovercard>
        
        {{-- SSL Status --}}
        <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $sslValid ? 'text-green-500' : 'text-red-500' }}" title="SSL Certificate">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
        </div>
        
        {{-- Lighthouse/Performance --}}
        <div class="rounded p-1.5 hover:bg-gray-100 transition-colors text-gray-400" title="Performance">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
        </div>
        
        {{-- Security --}}
        <div class="rounded p-1.5 hover:bg-gray-100 transition-colors text-gray-400" title="Security">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
        </div>
        
        {{-- SEO --}}
        <div class="rounded p-1.5 hover:bg-gray-100 transition-colors text-gray-400" title="SEO">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
        
        {{-- Analytics --}}
        <x-ui.hovercard position="bottom" align="center" :delay="150" width="w-64">
            <x-slot:trigger>
                <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $hasAnalytics ? 'text-green-500' : 'text-gray-400' }}" title="Analytics">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </div>
            </x-slot:trigger>
            <x-slot:content>
                <livewire:sites.hovercards.analytics-hovercard :site="$site" :key="'analytics-'.$site->id" />
            </x-slot:content>
        </x-ui.hovercard>
        
        {{-- Separator --}}
        <div class="h-4 w-px bg-gray-200 mx-1"></div>
        
        {{-- Backups --}}
        <x-ui.hovercard position="bottom" align="end" :delay="150" width="w-72">
            <x-slot:trigger>
                <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $hasBackups ? 'text-green-500' : 'text-gray-400' }}" title="Backups">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                    </svg>
                </div>
            </x-slot:trigger>
            <x-slot:content>
                <livewire:sites.hovercards.backups-hovercard :site="$site" :key="'backups-'.$site->id" />
            </x-slot:content>
        </x-ui.hovercard>
        
        {{-- Users --}}
        <div class="rounded p-1.5 hover:bg-gray-100 transition-colors text-gray-400" title="Users">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
        </div>
        
        {{-- WordPress --}}
        <x-ui.hovercard position="bottom" align="end" :delay="150" width="w-80">
            <x-slot:trigger>
                <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $site->wordpress_connected ? 'text-blue-500' : 'text-gray-400' }}" title="WordPress">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 19.5c-5.247 0-9.5-4.253-9.5-9.5S6.753 2.5 12 2.5s9.5 4.253 9.5 9.5-4.253 9.5-9.5 9.5z"/>
                    </svg>
                </div>
            </x-slot:trigger>
            <x-slot:content>
                <livewire:sites.hovercards.updates-hovercard :site="$site" :key="'updates-'.$site->id" />
            </x-slot:content>
        </x-ui.hovercard>
        
        {{-- Reports --}}
        <x-ui.hovercard position="bottom" align="end" :delay="150" width="w-64">
            <x-slot:trigger>
                <div class="rounded p-1.5 hover:bg-gray-100 transition-colors {{ $hasReports ? 'text-orange-500' : 'text-gray-400' }}" title="Reports">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
            </x-slot:trigger>
            <x-slot:content>
                <livewire:sites.hovercards.reports-hovercard :site="$site" :key="'reports-'.$site->id" />
            </x-slot:content>
        </x-ui.hovercard>
    </div>
    
    {{-- Separator --}}
    <div class="h-4 w-px bg-gray-200"></div>
    
    {{-- Quick Status Indicator (colored bar like in WPMUDEV) --}}
    <div class="w-12 flex justify-center">
        @if($site->has_issues)
            <div class="h-1.5 w-8 rounded-full bg-red-500"></div>
        @elseif($hasUpdates)
            <div class="h-1.5 w-8 rounded-full bg-amber-500"></div>
        @else
            <div class="h-1.5 w-8 rounded-full bg-gray-200"></div>
        @endif
    </div>
    
    {{-- Actions Menu --}}
    <div class="flex-shrink-0" x-data="{ open: false }">
        <button 
            @click="open = !open"
            class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
            </svg>
        </button>
        
        {{-- Dropdown menu --}}
        <div 
            x-show="open" 
            @click.away="open = false"
            x-transition
            class="absolute right-4 mt-1 w-48 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5 z-20"
        >
            <a href="{{ route('sites.overview', $site) }}" wire:navigate class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                View Dashboard
            </a>
            <a href="{{ route('sites.settings', $site) }}" wire:navigate class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                Site Settings
            </a>
            <hr class="my-1 border-gray-100">
            <a href="{{ $site->url }}" target="_blank" rel="noopener" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                Visit Site ↗
            </a>
            <a href="{{ $site->admin_url }}" target="_blank" rel="noopener" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                WP Admin ↗
            </a>
        </div>
    </div>
</div>
```

---

## 8. CSS for x-cloak

Add to `resources/css/app.css`:

```css
[x-cloak] { display: none !important; }
```

---

## 9. Usage in Dashboard

### `resources/views/livewire/sites/sites-list.blade.php`

```blade
<div class="space-y-2">
    @forelse($sites as $site)
        <x-sites.site-row :site="$site" />
    @empty
        <x-ui.empty-state 
            title="No sites yet"
            description="Connect your first WordPress site to get started."
            action-label="Connect Site"
            action-route="sites.connect"
        />
    @endforelse
</div>
```

---

## Implementation Notes

1. **Lazy Loading**: Each hovercard loads its data only when it becomes visible (on hover), not on page load. This prevents excessive data loading.

2. **Hover Delay**: The 150-200ms delay prevents accidental display of hovercards when the user just moves the mouse over icons.

3. **Loading States**: Each hovercard has a skeleton loader for smooth UX.

4. **Actions**: Action buttons (Update, Create Backup, etc.) dispatch Livewire events to trigger async jobs.

5. **Keys**: We use `:key="'prefix-'.$site->id"` to prevent re-render issues in lists.

6. **Z-Index**: Hovercards have `z-50` to appear above other elements.

7. **Positioning**: We use `align="end"` for hovercards on the right side of the list to prevent overflow.

---

## Required Model Methods

Make sure your `Site` model has these methods/relationships:

```php
// app/Models/Site.php

public function getWordPressData(): array
{
    // Fetch from WordPress connector plugin
}

public function getAnalyticsData(Carbon $start, Carbon $end): array
{
    // Fetch from analytics integration
}

public function backups(): HasMany
{
    return $this->hasMany(Backup::class);
}

public function reports(): HasMany
{
    return $this->hasMany(Report::class);
}

public function uptimeMonitor(): HasOne
{
    return $this->hasOne(UptimeMonitor::class);
}

public function backupDestination(): BelongsTo
{
    return $this->belongsTo(BackupDestination::class);
}
```
