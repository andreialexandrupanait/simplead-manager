@props(['wireModel' => null])

<div x-data="tiptapEditor(@entangle($wireModel))" class="rounded-lg border border-gray-300 bg-white overflow-hidden focus-within:border-purple-500 focus-within:ring-1 focus-within:ring-purple-500">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
        <button type="button" @click="toggleBold()" :class="isActive.bold ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 text-xs font-bold transition" title="Bold">B</button>
        <button type="button" @click="toggleItalic()" :class="isActive.italic ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 text-xs italic transition" title="Italic">I</button>
        <button type="button" @click="toggleUnderline()" :class="isActive.underline ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 text-xs underline transition" title="Underline">U</button>
        <button type="button" @click="toggleStrike()" :class="isActive.strike ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 text-xs line-through transition" title="Strikethrough">S</button>

        <div class="mx-1 h-5 w-px bg-gray-300"></div>

        <button type="button" @click="toggleH2()" :class="isActive.h2 ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded px-1.5 py-1 text-xs font-semibold transition" title="Heading 2">H2</button>
        <button type="button" @click="toggleH3()" :class="isActive.h3 ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded px-1.5 py-1 text-xs font-semibold transition" title="Heading 3">H3</button>

        <div class="mx-1 h-5 w-px bg-gray-300"></div>

        <button type="button" @click="toggleBulletList()" :class="isActive.bulletList ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 transition" title="Bullet List">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button type="button" @click="toggleOrderedList()" :class="isActive.orderedList ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 transition" title="Ordered List">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h10M7 16h10M3 8h.01M3 12h.01M3 16h.01"/></svg>
        </button>
        <button type="button" @click="toggleBlockquote()" :class="isActive.blockquote ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 transition" title="Quote">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        </button>

        <div class="mx-1 h-5 w-px bg-gray-300"></div>

        <button type="button" @click="isActive.link ? unsetLink() : setLink()" :class="isActive.link ? 'bg-gray-200 text-gray-900' : 'text-gray-500 hover:bg-gray-100'" class="rounded p-1.5 transition" title="Link">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
        </button>

        <div class="mx-1 h-5 w-px bg-gray-300"></div>

        <button type="button" @click="undo()" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 transition" title="Undo">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>
        </button>
        <button type="button" @click="redo()" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 transition" title="Redo">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 00-5 5v2m15-7l-4-4m4 4l-4 4"/></svg>
        </button>
    </div>

    {{-- Editor content area --}}
    <div x-ref="editorContent" class="min-h-[400px]"></div>
</div>
