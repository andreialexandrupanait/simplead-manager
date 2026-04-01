import './bootstrap';

import Chart from 'chart.js/auto';
window.Chart = Chart;

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

    Alpine.data('sortableList', () => ({
        enabled: false,
        _dragEl: null,
        _placeholder: null,
        _handleClicked: false,
        _handlers: {},

        init() {
            this.$watch('enabled', (val) => {
                this.$nextTick(() => {
                    if (val) this._setup();
                    else this._teardown();
                });
            });
        },

        _getWire() {
            if (this.$wire) return this.$wire;
            const wireEl = this.$el.closest('[wire\\:id]');
            if (wireEl) return Livewire.find(wireEl.getAttribute('wire:id'));
            return null;
        },

        _setup() {
            const container = this.$refs.sortableContainer;
            if (!container) return;

            this._handlers = {
                pointerdown: (e) => {
                    this._handleClicked = !!e.target.closest('.drag-handle');
                },

                dragstart: (e) => {
                    if (!this._handleClicked) { e.preventDefault(); return; }
                    const row = e.target.closest('[data-site-id]');
                    if (!row) { e.preventDefault(); return; }
                    this._dragEl = row;
                    row.style.opacity = '0.4';
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');

                    this._placeholder = document.createElement('div');
                    this._placeholder.style.cssText = 'height:3px;background:#3b82f6;border-radius:2px;margin:2px 16px;';
                },

                dragover: (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    const row = e.target.closest('[data-site-id]');
                    if (!row || row === this._dragEl) return;

                    const rect = row.getBoundingClientRect();
                    const mid = rect.top + rect.height / 2;

                    if (e.clientY < mid) {
                        row.parentNode.insertBefore(this._placeholder, row);
                    } else {
                        row.parentNode.insertBefore(this._placeholder, row.nextSibling);
                    }
                },

                dragend: () => {
                    if (this._dragEl) {
                        this._dragEl.style.opacity = '';
                    }
                    if (this._placeholder?.parentNode) {
                        this._placeholder.remove();
                    }
                    this._dragEl = null;
                    this._handleClicked = false;
                },

                drop: (e) => {
                    e.preventDefault();
                    if (!this._dragEl || !this._placeholder?.parentNode) return;
                    this._placeholder.parentNode.insertBefore(this._dragEl, this._placeholder);
                    this._placeholder.remove();
                    this._dragEl.style.opacity = '';
                    this._dragEl = null;
                },
            };

            for (const [evt, fn] of Object.entries(this._handlers)) {
                container.addEventListener(evt, fn);
            }

            container.querySelectorAll('[data-site-id]').forEach((row) => {
                row.setAttribute('draggable', 'true');
            });
        },

        _teardown() {
            const container = this.$refs.sortableContainer;
            if (!container) return;

            for (const [evt, fn] of Object.entries(this._handlers)) {
                container.removeEventListener(evt, fn);
            }
            this._handlers = {};

            container.querySelectorAll('[data-site-id]').forEach((row) => {
                row.removeAttribute('draggable');
                row.style.opacity = '';
            });

            if (this._placeholder?.parentNode) this._placeholder.remove();
            this._dragEl = null;
        },

        saveOrder() {
            const container = this.$refs.sortableContainer;
            if (!container) return;
            const ids = [...container.querySelectorAll('[data-site-id]')]
                .map(el => Number(el.dataset.siteId));
            const wire = this._getWire();
            if (wire) wire.saveReorder(ids);
        },

        destroy() {
            this._teardown();
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
            let maxRight = window.innerWidth - 8;

            let left = this._alignRight ? rect.right - pw : rect.left;

            // Clamp to viewport boundaries
            if (left < minLeft) left = minLeft;
            if (left + pw > maxRight) left = maxRight - pw;

            panel.style.left = Math.round(left) + 'px';
        },
    }));
});
