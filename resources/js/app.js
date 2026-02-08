import './bootstrap';

import Chart from 'chart.js/auto';
window.Chart = Chart;

import Sortable from 'sortablejs';

// Alpine.js comes with Livewire 3 — no manual import needed
document.addEventListener('alpine:init', () => {
    Alpine.data('tooltip', () => ({
        show: false,
        panelEl: null,

        reposition() {
            if (!this.panelEl) return;
            let trigger = this.$refs.trigger || this.$el;
            let rect = trigger.getBoundingClientRect();
            let pw = this.panelEl.offsetWidth;
            let ph = this.panelEl.offsetHeight;

            let left = rect.left + rect.width / 2 - pw / 2;
            if (left < 8) left = 8;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            this.panelEl.style.left = Math.round(left) + 'px';

            let spaceAbove = rect.top;
            if (spaceAbove >= ph + 8) {
                this.panelEl.style.top = Math.round(rect.top - ph - 6) + 'px';
                this.panelEl.dataset.position = 'top';
            } else {
                this.panelEl.style.top = Math.round(rect.bottom + 6) + 'px';
                this.panelEl.dataset.position = 'bottom';
            }
        },
    }));

    Alpine.data('dropdown', (opts = {}) => ({
        open: false,
        panelEl: null,
        _alignRight: opts.alignRight !== undefined ? opts.alignRight : true,
        _scrollHandler: null,
        _resizeHandler: null,

        init() {
            this._scrollHandler = () => { if (this.open) this.reposition(); };
            this._resizeHandler = () => { if (this.open) this.reposition(); };
            window.addEventListener('scroll', this._scrollHandler, { passive: true });
            window.addEventListener('resize', this._resizeHandler, { passive: true });
        },

        destroy() {
            if (this._scrollHandler) window.removeEventListener('scroll', this._scrollHandler);
            if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
        },

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
            this.panelEl.style.left = Math.round(this._alignRight ? rect.right - pw : rect.left) + 'px';
        },
    }));

    Alpine.data('sortableList', () => ({
        enabled: false,
        sortableInstance: null,

        init() {
            this.$watch('enabled', (val) => {
                if (val) {
                    this.$nextTick(() => this.createSortable());
                } else {
                    this.destroySortable();
                }
            });
        },

        createSortable() {
            let container = this.$refs.sortableContainer;
            if (!container || this.sortableInstance) return;
            this.sortableInstance = Sortable.create(container, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'opacity-30',
            });
        },

        destroySortable() {
            if (this.sortableInstance) {
                this.sortableInstance.destroy();
                this.sortableInstance = null;
            }
        },

        saveOrder() {
            let container = this.$refs.sortableContainer;
            if (!container) return;
            let orderedIds = [];
            for (let i = 0; i < container.children.length; i++) {
                let id = container.children[i].getAttribute('data-site-id');
                if (id) orderedIds.push(parseInt(id, 10));
            }
            if (orderedIds.length > 0) {
                this.$wire.saveReorder(orderedIds);
            }
        },

        destroy() {
            this.destroySortable();
        },
    }));

    Alpine.data('toast', () => ({
        show: false,
        message: '',
        type: 'success',
        _timeout: null,
        notify(detail) {
            this.message = detail.message;
            this.type = detail.type ?? 'success';
            this.show = true;
            clearTimeout(this._timeout);
            this._timeout = setTimeout(() => this.show = false, 4000);
        },
    }));

    Alpine.data('hovercard', (opts = {}) => ({
        open: false,
        panelEl: null,
        openTimer: null,
        closeTimer: null,
        _scrollHandler: null,
        _resizeHandler: null,
        _alignRight: opts.alignRight !== undefined ? opts.alignRight : true,

        init() {
            this._scrollHandler = () => { if (this.open) this.reposition(); };
            this._resizeHandler = () => { if (this.open) this.reposition(); };
            window.addEventListener('scroll', this._scrollHandler, { passive: true, capture: true });
            window.addEventListener('resize', this._resizeHandler, { passive: true });
        },

        destroy() {
            clearTimeout(this.openTimer);
            clearTimeout(this.closeTimer);
            if (this._scrollHandler) window.removeEventListener('scroll', this._scrollHandler, { capture: true });
            if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
        },

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

        reposition() {
            let trigger = this.$refs.trigger;
            let panel = this.panelEl;
            if (!trigger || !panel) return;
            if (!panel.isConnected) return;

            let rect = trigger.getBoundingClientRect();
            let pw = panel.offsetWidth;
            let ph = panel.offsetHeight;

            if (pw === 0 && ph === 0) {
                requestAnimationFrame(() => this.reposition());
                return;
            }

            let spaceBelow = window.innerHeight - rect.bottom;

            if (spaceBelow < ph + 8 && rect.top > ph + 8) {
                panel.style.top = Math.round(rect.top - ph - 4) + 'px';
            } else {
                panel.style.top = Math.round(rect.bottom + 4) + 'px';
            }

            let sidebar = document.querySelector('[data-sidebar]');
            let minLeft = sidebar ? sidebar.getBoundingClientRect().right + 4 : 8;

            let left = this._alignRight ? rect.right - pw : rect.left;
            if (left < minLeft) left = minLeft;
            if (!this._alignRight && left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            panel.style.left = Math.round(left) + 'px';
        },
    }));
});
