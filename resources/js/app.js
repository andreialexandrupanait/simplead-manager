import './bootstrap';

import Chart from 'chart.js/auto';
window.Chart = Chart;

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';

window.TipTapEditor = Editor;
window.TipTapStarterKit = StarterKit;
window.TipTapLink = Link;
window.TipTapUnderline = Underline;
window.TipTapPlaceholder = Placeholder;

// Alpine.js comes with Livewire 3 — no manual import needed
document.addEventListener('alpine:init', () => {
    Alpine.data('slideshow', (initialSlides = []) => ({
        slides: initialSlides,
        current: 0,
        playing: true,
        _interval: null,

        init() {
            // Preload all slide images
            this.slides.forEach(slide => {
                if (slide.image) {
                    const img = new Image();
                    img.src = slide.image;
                }
            });
            this._startTimer();
        },

        destroy() {
            this._stopTimer();
        },

        _startTimer() {
            this._stopTimer();
            if (this.slides.length > 1) {
                this._interval = setInterval(() => this.next(), 5500);
            }
        },

        _stopTimer() {
            if (this._interval) {
                clearInterval(this._interval);
                this._interval = null;
            }
        },

        next() {
            this.current = (this.current + 1) % this.slides.length;
        },

        prev() {
            this.current = (this.current - 1 + this.slides.length) % this.slides.length;
        },

        togglePlay() {
            this.playing = !this.playing;
            if (this.playing) {
                this._startTimer();
            } else {
                this._stopTimer();
            }
        },

        goTo(index) {
            this.current = index;
            if (this.playing) this._startTimer();
        },
    }));

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

    Alpine.data('toastStack', () => ({
        toasts: [],
        _nextId: 1,

        notify(detail) {
            if (this.toasts.length >= 5) {
                this.toasts.shift();
            }
            const id = this._nextId++;
            const toast = {
                id,
                message: detail.message ?? '',
                type: detail.type ?? 'success',
                visible: true,
            };
            this.toasts.push(toast);
            setTimeout(() => this.dismiss(id), 5000);
        },

        dismiss(id) {
            const idx = this.toasts.findIndex(t => t.id === id);
            if (idx !== -1) this.toasts.splice(idx, 1);
        },

        colorClass(type) {
            if (type === 'success') return 'bg-green-50 text-green-800 dark:bg-green-900/80 dark:text-green-200';
            if (type === 'error') return 'bg-red-50 text-red-800 dark:bg-red-900/80 dark:text-red-200';
            if (type === 'warning') return 'bg-yellow-50 text-yellow-800 dark:bg-yellow-900/80 dark:text-yellow-200';
            return 'bg-blue-50 text-blue-800 dark:bg-blue-900/80 dark:text-blue-200';
        },
    }));

    Alpine.data('sortableList', () => ({
        enabled: false,
        orderedIds: [],
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

        _collectIds() {
            const container = this.$refs.sortableContainer;
            if (!container) return;
            this.orderedIds = [...container.querySelectorAll('[data-site-id]')]
                .map(el => Number(el.dataset.siteId));
        },

        _setup() {
            const container = this.$refs.sortableContainer;
            if (!container) return;

            // Prevent Livewire morphdom from reverting drag-and-drop DOM changes
            container.setAttribute('wire:ignore', '');

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
                    this._collectIds();
                },
            };

            for (const [evt, fn] of Object.entries(this._handlers)) {
                container.addEventListener(evt, fn);
            }

            container.querySelectorAll('[data-site-id]').forEach((row) => {
                row.setAttribute('draggable', 'true');
            });

            this._collectIds();
        },

        _teardown() {
            const container = this.$refs.sortableContainer;
            if (!container) return;

            container.removeAttribute('wire:ignore');

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
            this.orderedIds = [];
        },

        destroy() {
            this._teardown();
        },
    }));

    Alpine.data('tiptapEditor', (initialContent = '') => ({
        editor: null,
        content: initialContent,
        isActive: {},

        init() {
            this.editor = new window.TipTapEditor({
                element: this.$refs.editorContent,
                extensions: [
                    window.TipTapStarterKit.configure({
                        heading: { levels: [2, 3, 4] },
                    }),
                    window.TipTapLink.configure({
                        openOnClick: false,
                        HTMLAttributes: { class: 'text-purple-600 underline' },
                    }),
                    window.TipTapUnderline,
                    window.TipTapPlaceholder.configure({
                        placeholder: 'Start writing your article...',
                    }),
                ],
                content: this.content,
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm max-w-none focus:outline-none min-h-[400px] px-4 py-3 prose-headings:text-gray-900 prose-p:text-gray-700',
                    },
                },
                onUpdate: ({ editor }) => {
                    this.content = editor.getHTML();
                    this.$dispatch('input', this.content);
                },
                onSelectionUpdate: ({ editor }) => {
                    this._updateActive(editor);
                },
                onTransaction: ({ editor }) => {
                    this._updateActive(editor);
                },
            });

            // Watch for external content changes (e.g., AI generation)
            this.$watch('content', (val) => {
                if (this.editor && val !== this.editor.getHTML()) {
                    this.editor.commands.setContent(val, false);
                }
            });
        },

        _updateActive(editor) {
            this.isActive = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                underline: editor.isActive('underline'),
                strike: editor.isActive('strike'),
                h2: editor.isActive('heading', { level: 2 }),
                h3: editor.isActive('heading', { level: 3 }),
                bulletList: editor.isActive('bulletList'),
                orderedList: editor.isActive('orderedList'),
                blockquote: editor.isActive('blockquote'),
                link: editor.isActive('link'),
            };
        },

        toggleBold() { this.editor.chain().focus().toggleBold().run(); },
        toggleItalic() { this.editor.chain().focus().toggleItalic().run(); },
        toggleUnderline() { this.editor.chain().focus().toggleUnderline().run(); },
        toggleStrike() { this.editor.chain().focus().toggleStrike().run(); },
        toggleH2() { this.editor.chain().focus().toggleHeading({ level: 2 }).run(); },
        toggleH3() { this.editor.chain().focus().toggleHeading({ level: 3 }).run(); },
        toggleBulletList() { this.editor.chain().focus().toggleBulletList().run(); },
        toggleOrderedList() { this.editor.chain().focus().toggleOrderedList().run(); },
        toggleBlockquote() { this.editor.chain().focus().toggleBlockquote().run(); },
        setLink() {
            const url = prompt('URL:');
            if (url) {
                this.editor.chain().focus().setLink({ href: url }).run();
            }
        },
        unsetLink() { this.editor.chain().focus().unsetLink().run(); },
        undo() { this.editor.chain().focus().undo().run(); },
        redo() { this.editor.chain().focus().redo().run(); },

        destroy() {
            if (this.editor) {
                this.editor.destroy();
            }
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
