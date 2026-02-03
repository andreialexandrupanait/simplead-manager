@props(['align' => 'right', 'width' => '48'])

@php
$widthClass = match($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    '64' => 'w-64',
};
$originClass = $align === 'right' ? 'origin-top-right' : 'origin-top-left';
@endphp

<div x-data="{
    open: false,
    panelEl: null,
    toggle() {
        if (this.open) {
            this.open = false;
            return;
        }
        this.open = true;
        this.$nextTick(() => this.reposition());
    },
    close(e) {
        if (!this.open) return;
        if (this.$refs.trigger && this.$refs.trigger.contains(e.target)) return;
        if (this.panelEl && this.panelEl.contains(e.target)) return;
        this.open = false;
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
        @if($align === 'right')
            this.panelEl.style.left = Math.round(rect.right - pw) + 'px';
        @else
            this.panelEl.style.left = Math.round(rect.left) + 'px';
        @endif
    },
}" x-init="
    let handler = () => { if (open) reposition(); };
    window.addEventListener('scroll', handler, { passive: true });
    window.addEventListener('resize', handler, { passive: true });
" @click.window="close($event)">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div x-show="open" @click="open = false"
             x-init="panelEl = $el"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="{{ $widthClass }} {{ $originClass }} rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5"
             style="display: none; position: fixed; z-index: 9999;">
            {{ $slot }}
        </div>
    </template>
</div>
