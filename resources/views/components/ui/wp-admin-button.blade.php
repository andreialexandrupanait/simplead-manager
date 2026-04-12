@props(['site'])

@php
    $adminUsers = $site->siteUsers()->admins()->orderBy('display_name')->get();
    $selectedUser = $site->wpAdminUser;
    $hasAdmins = $adminUsers->count() > 0;
@endphp

<div class="inline-flex items-center">
    {{-- Main button --}}
    <button wire:click="openWpAdmin"
            wire:loading.attr="disabled"
            wire:target="openWpAdmin"
            class="inline-flex items-center gap-2 rounded-l-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition disabled:opacity-50 disabled:cursor-not-allowed {{ !$hasAdmins ? 'rounded-r-lg' : 'border-r-0' }}">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
        </svg>
        <span wire:loading.remove wire:target="openWpAdmin">
            WP Admin
            @if($selectedUser)
                <span class="text-gray-400 dark:text-gray-500 font-normal">as {{ $selectedUser->display_name ?: $selectedUser->username }}</span>
            @endif
        </span>
        <span wire:loading wire:target="openWpAdmin">Opening...</span>
    </button>

    {{-- Dropdown chevron --}}
    @if($hasAdmins)
        <x-ui.dropdown align="right" width="56">
            <x-slot:trigger>
                <button type="button"
                        class="inline-flex items-center rounded-r-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-2 text-gray-500 dark:text-gray-400 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </x-slot:trigger>

            <div class="px-3 py-2 text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Login as</div>

            {{-- Default (first admin) option --}}
            <button wire:click="setWpAdminUser(null)"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                @if(!$selectedUser)
                    <svg class="h-4 w-4 text-accent shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                @else
                    <span class="h-4 w-4 shrink-0"></span>
                @endif
                <span>Default (first admin)</span>
            </button>

            <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>

            @foreach($adminUsers as $admin)
                <button wire:click="setWpAdminUser({{ $admin->id }})"
                        class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    @if($selectedUser && $selectedUser->id === $admin->id)
                        <svg class="h-4 w-4 text-accent shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                    @else
                        <span class="h-4 w-4 shrink-0"></span>
                    @endif
                    <div class="text-left truncate">
                        <span>{{ $admin->display_name ?: $admin->username }}</span>
                        @if($admin->display_name && $admin->display_name !== $admin->username)
                            <span class="text-gray-400 dark:text-gray-500 text-xs">({{ $admin->username }})</span>
                        @endif
                    </div>
                </button>
            @endforeach
        </x-ui.dropdown>
    @endif
</div>
