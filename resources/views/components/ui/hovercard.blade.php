@props(['align' => 'right', 'width' => 'w-80'])

<span x-data="{
    open: false,
    panelEl: null,
    openTimer: null,
    closeTimer: null,

    startOpen() {
        clearTimeout(this.closeTimer);
        this.openTimer = setTimeout(() => {
            this.open = true;
            this.$nextTick(() => this.reposition());
        }, 200);
    },

    startClose() {
        clearTimeout(this.openTimer);
        this.closeTimer = setTimeout(() => { this.open = false; }, 150);
    },

    cancelClose() {
        clearTimeout(this.closeTimer);
    },

    reposition() {
        let trigger = this.$refs.trigger;
        if (!trigger || !this.panelEl) return;
        let rect = trigger.getBoundingClientRect();
        let pw = this.panelEl.offsetWidth;
        let ph = this.panelEl.offsetHeight;
        let spaceBelow = window.innerHeight - rect.bottom;

        if (spaceBelow < ph + 8 && rect.top > ph + 8) {
            this.panelEl.style.top = Math.round(rect.top - ph - 4) + 'px';
        } else {
            this.panelEl.style.top = Math.round(rect.bottom + 4) + 'px';
        }

        let sidebar = document.querySelector('[data-sidebar]');
        let minLeft = sidebar ? sidebar.getBoundingClientRect().right + 4 : 8;
        @if($align === 'right')
            let left = rect.right - pw;
            if (left < minLeft) left = minLeft;
            this.panelEl.style.left = Math.round(left) + 'px';
        @else
            let left = rect.left;
            if (left < minLeft) left = minLeft;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            this.panelEl.style.left = Math.round(left) + 'px';
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
             x-init="panelEl = $el"
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
