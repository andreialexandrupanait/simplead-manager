# Fix Tooltip & Hovercard Positioning

## Problem
Tooltips and hovercards appear in wrong positions (not aligned with their trigger icons). The teleported panels render at incorrect coordinates.

## Root Causes

### Tooltip (`resources/views/components/ui/tooltip.blade.php`)
- `this.$el.getBoundingClientRect()` gets the wrapper span position, not the actual icon inside
- No `x-ref="trigger"` on the visual element
- No viewport bounds checking (can go off-screen)
- No scroll/resize listeners

### Hovercard (`resources/views/components/ui/hovercard.blade.php`)
- `$refs.panel` doesn't work for teleported elements (Alpine.js limitation)
- `x-init="panelEl = $el"` sometimes doesn't execute properly in Livewire `@foreach` loops
- When `panelEl` stays null, `reposition()` silently returns and panel renders at `top:0; left:0`

## Required Fixes

### Tooltip - Replace entire file with:

```blade
@props(['text'])

<span x-data="{
    show: false,
    panelEl: null,
    reposition() {
        if (!this.panelEl) return;
        let trigger = this.$refs.trigger || this.$el;
        let rect = trigger.getBoundingClientRect();
        let pw = this.panelEl.offsetWidth;
        let ph = this.panelEl.offsetHeight;

        // Horizontal: center above trigger, clamp to viewport
        let left = rect.left + rect.width / 2 - pw / 2;
        if (left < 8) left = 8;
        if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
        this.panelEl.style.left = Math.round(left) + 'px';

        // Vertical: prefer above, flip below if no space
        let spaceAbove = rect.top;
        if (spaceAbove >= ph + 8) {
            this.panelEl.style.top = Math.round(rect.top - ph - 6) + 'px';
            this.panelEl.dataset.position = 'top';
        } else {
            this.panelEl.style.top = Math.round(rect.bottom + 6) + 'px';
            this.panelEl.dataset.position = 'bottom';
        }
    }
}" class="inline-flex"
    @mouseenter="show = true; $nextTick(() => reposition())"
    @mouseleave="show = false">
    <span x-ref="trigger" class="inline-flex">
        {{ $slot }}
    </span>

    <template x-teleport="body">
        <div x-show="show" x-cloak
             x-init="panelEl = $el"
             x-transition:enter="transition-opacity duration-100"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity duration-75"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="pointer-events-none whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg"
             style="display: none; position: fixed; z-index: 10000;">
            {{ $text }}
            <div class="absolute left-1/2 -translate-x-1/2 border-4 border-transparent"
                 :class="$el.parentElement.dataset.position === 'bottom'
                    ? 'bottom-full border-b-gray-900'
                    : 'top-full border-t-gray-900'"></div>
        </div>
    </template>
</span>
```

### Hovercard - Replace entire file with:

```blade
@props(['align' => 'right', 'width' => 'w-80'])

<span x-data="{
    open: false,
    panelEl: null,
    openTimer: null,
    closeTimer: null,
    panelId: 'hc-' + Math.random().toString(36).substr(2, 9),

    startOpen() {
        clearTimeout(this.closeTimer);
        this.openTimer = setTimeout(() => {
            this.open = true;
            this.$nextTick(() => {
                requestAnimationFrame(() => this.reposition());
            });
        }, 200);
    },

    startClose() {
        clearTimeout(this.openTimer);
        this.closeTimer = setTimeout(() => { this.open = false; }, 150);
    },

    cancelClose() {
        clearTimeout(this.closeTimer);
    },

    getPanel() {
        if (!this.panelEl) {
            this.panelEl = document.getElementById(this.panelId);
        }
        return this.panelEl;
    },

    reposition() {
        let trigger = this.$refs.trigger;
        let panel = this.getPanel();
        if (!trigger || !panel) return;

        let rect = trigger.getBoundingClientRect();
        let pw = panel.offsetWidth;
        let ph = panel.offsetHeight;
        let spaceBelow = window.innerHeight - rect.bottom;

        // Vertical positioning with smart flip
        if (spaceBelow < ph + 8 && rect.top > ph + 8) {
            panel.style.top = Math.round(rect.top - ph - 4) + 'px';
        } else {
            panel.style.top = Math.round(rect.bottom + 4) + 'px';
        }

        // Horizontal positioning with sidebar awareness
        let sidebar = document.querySelector('[data-sidebar]');
        let minLeft = sidebar ? sidebar.getBoundingClientRect().right + 4 : 8;

        @if($align === 'right')
            let left = rect.right - pw;
            if (left < minLeft) left = minLeft;
            panel.style.left = Math.round(left) + 'px';
        @else
            let left = rect.left;
            if (left < minLeft) left = minLeft;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            panel.style.left = Math.round(left) + 'px';
        @endif
    },
}" x-init="
    let handler = () => { if (open) reposition(); };
    window.addEventListener('scroll', handler, { passive: true, capture: true });
    window.addEventListener('resize', handler, { passive: true });
" class="inline-flex">
    <span x-ref="trigger" @mouseenter="startOpen()" @mouseleave="startClose()">
        {{ $trigger }}
    </span>

    <template x-teleport="body">
        <div x-show="open" x-cloak
             :id="panelId"
             x-init="$nextTick(() => { panelEl = $el; })"
             @mouseenter="cancelClose()"
             @mouseleave="startClose()"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="{{ $width }} rounded-lg bg-white p-4 shadow-lg ring-1 ring-gray-950/5"
             style="display: none; position: fixed; z-index: 9999;">
            {{ $slot }}
        </div>
    </template>
</span>
```

## Key Changes Summary

| Component | Issue | Fix |
|-----------|-------|-----|
| Tooltip | Wrong element measured | Added `x-ref="trigger"` wrapper, use `$refs.trigger.getBoundingClientRect()` |
| Tooltip | No viewport clamping | Added left/right bounds checking |
| Tooltip | Always appears above | Smart flip - appears below if no space above |
| Tooltip | Static arrow | Dynamic arrow direction with `:class` |
| Hovercard | `$refs.panel` fails through teleport | Generate unique `panelId`, use `document.getElementById()` fallback |
| Hovercard | `x-init` unreliable in loops | Added `:id="panelId"` + `$nextTick` wrapper |

## Files to Update
1. `resources/views/components/ui/tooltip.blade.php`
2. `resources/views/components/ui/hovercard.blade.php`
