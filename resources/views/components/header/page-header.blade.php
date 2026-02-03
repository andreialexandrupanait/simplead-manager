@props(['title' => 'SimpleAd Manager', 'siteContext' => null])

@php
    $routeName = Route::currentRouteName();
    $user = auth()->user();

    // Build breadcrumbs and derive page title
    $crumbs = [];
    $pageTitle = $title;
    $isDashboard = ($routeName === 'dashboard');

    // Time-based Romanian greeting for dashboard
    $greeting = null;
    if ($isDashboard) {
        $hour = (int) now()->format('H');
        if ($hour >= 5 && $hour < 12) {
            $greeting = 'Bună dimineața';
        } elseif ($hour >= 12 && $hour < 18) {
            $greeting = 'Bună ziua';
        } else {
            $greeting = 'Bună seara';
        }
        $greeting .= ', ' . $user->name;
        $pageTitle = 'Dashboard';
    }

    // Site-context routes: Dashboard > Sites > SiteName > PageTitle
    if ($siteContext && str_starts_with($routeName, 'sites.') && $routeName !== 'sites.index' && $routeName !== 'sites.create') {
        $crumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];
        $crumbs[] = ['label' => 'Sites', 'url' => route('sites.index')];
        $crumbs[] = ['label' => $siteContext->name, 'url' => route('sites.overview', $siteContext)];

        $pageTitle = match($routeName) {
            'sites.overview' => 'Overview',
            'sites.plugins' => 'Plugins & Themes',
            'sites.updates' => 'Updates',
            'sites.security' => 'Security',
            'sites.performance' => 'Performance',
            'sites.backups' => 'Backups',
            'sites.uptime' => 'Uptime',
            'sites.links' => 'Links',
            'sites.analytics' => 'Analytics',
            'sites.search-console' => 'Search Console',
            'sites.reports' => 'Reports',
            'sites.settings' => 'Site Settings',
            default => $title,
        };

        // For overview, remove the site name crumb (site name IS the page)
        if ($routeName === 'sites.overview') {
            array_pop($crumbs);
            $pageTitle = $siteContext->name;
        }
    }
    // sites.create
    elseif ($routeName === 'sites.create') {
        $crumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];
        $crumbs[] = ['label' => 'Sites', 'url' => route('sites.index')];
        $pageTitle = 'Add New Site';
    }
    // Clients detail
    elseif ($routeName === 'clients.show') {
        $crumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];
        $crumbs[] = ['label' => 'Clients', 'url' => route('clients.index')];
    }
    // Settings pages
    elseif (str_starts_with($routeName, 'settings.')) {
        $crumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];

        $settingsTitle = match($routeName) {
            'settings.general' => 'General',
            'settings.notifications' => 'Notifications',
            'settings.profile' => 'Profile',
            'settings.integrations' => 'Integrations',
            'settings.report-templates' => 'Report Templates',
            default => $title,
        };

        if ($routeName !== 'settings.general') {
            $crumbs[] = ['label' => 'Settings', 'url' => route('settings.general')];
        }

        $pageTitle = $settingsTitle;
    }
    // Top-level index pages: Dashboard > PageTitle
    elseif (!$isDashboard) {
        $crumbs[] = ['label' => 'Dashboard', 'url' => route('dashboard')];
    }
@endphp

<header class="sticky top-0 z-30 border-b bg-white shadow-sm">
    <div class="flex h-16 items-center px-6">
        {{-- Desktop sidebar toggle --}}
        <button @click="toggleSidebar()" class="mr-3 hidden lg:block text-gray-500 hover:text-gray-700 transition">
            <x-icons.menu class="h-5 w-5" />
        </button>

        {{-- Mobile menu toggle --}}
        <button @click="mobileSidebarOpen = true" class="mr-3 lg:hidden text-gray-500">
            <x-icons.menu class="h-6 w-6" />
        </button>

        {{-- Title + breadcrumb (left side) --}}
        <div class="flex min-w-0 flex-1 flex-col justify-center">
            @if($isDashboard)
                <h1 class="text-sm font-bold tracking-wide text-gray-900 truncate leading-tight">{{ $greeting }}</h1>
                <span class="text-xs text-gray-400 mt-0.5">Dashboard</span>
            @else
                <h1 class="text-sm font-bold tracking-wide text-gray-900 uppercase truncate leading-tight">{{ $pageTitle }}</h1>
                @if(count($crumbs) > 0)
                    <nav class="hidden sm:flex items-center gap-1 mt-0.5">
                        @foreach($crumbs as $crumb)
                            <a href="{{ $crumb['url'] }}" class="text-xs text-gray-400 hover:text-gray-600 transition whitespace-nowrap">{{ $crumb['label'] }}</a>
                            <svg class="h-3 w-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            @if($loop->last)
                                <span class="text-xs text-gray-400 whitespace-nowrap">{{ $pageTitle }}</span>
                            @endif
                        @endforeach
                    </nav>
                @endif
            @endif
        </div>

        {{-- Right side actions --}}
        <div class="flex items-center gap-3 ml-4">
            {{-- Search --}}
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="text-gray-400 hover:text-gray-600">
                    <x-icons.search class="h-5 w-5" />
                </button>
            </div>

            {{-- Notifications --}}
            <livewire:components.notification-dropdown />

            {{-- Avatar dropdown --}}
            <x-ui.dropdown align="right" width="48">
                <x-slot:trigger>
                    <button class="flex items-center gap-2">
                        <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium">
                            {{ auth()->user()->initials }}
                        </div>
                    </button>
                </x-slot:trigger>
                <a href="{{ route('settings.profile') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profile</a>
                <a href="{{ route('settings.general') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Log out</button>
                </form>
            </x-ui.dropdown>
        </div>
    </div>
</header>
