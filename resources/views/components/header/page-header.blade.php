@props(['title' => 'SimpleAd Manager', 'siteContext' => null])

@php
    $routeName = Route::currentRouteName();
    $user = auth()->user();

    // Build breadcrumbs and derive page title
    $crumbs = [];
    $pageTitle = $title;
    $isDashboard = ($routeName === 'dashboard');

    // Time-based greeting for dashboard
    $greeting = null;
    if ($isDashboard) {
        $hour = (int) now()->format('H');
        if ($hour >= 5 && $hour < 12) {
            $greeting = __('Good morning');
        } elseif ($hour >= 12 && $hour < 18) {
            $greeting = __('Good afternoon');
        } else {
            $greeting = __('Good evening');
        }
        $greeting .= ', ' . $user->name;
        $pageTitle = __('Dashboard');
    }

    // Site-context routes: Dashboard > Sites > SiteName > PageTitle
    if ($siteContext && str_starts_with($routeName, 'sites.') && $routeName !== 'sites.index' && $routeName !== 'sites.create') {
        $crumbs[] = ['label' => __('Dashboard'), 'url' => route('dashboard')];
        $crumbs[] = ['label' => __('Sites'), 'url' => route('dashboard')];
        $crumbs[] = ['label' => $siteContext->name, 'url' => route('sites.overview', $siteContext)];

        $pageTitle = match($routeName) {
            'sites.overview' => __('Site Overview'),
            'sites.plugins' => __('Plugins'),
            'sites.security' => __('Security'),
            'sites.security.hardening' => __('Hardening'),
            'sites.security.login' => __('Login Protection'),
            'sites.security.captcha' => __('Captcha'),
            'sites.security.scanning' => __('Scanning'),
            'sites.security.activity' => __('Activity'),
            'sites.security.users' => __('Users'),
            'sites.security.ip-management' => __('IP Management'),
            'sites.tweaks' => __('Tweaks'),
            'sites.tweaks.performance' => __('Performance'),
            'sites.tweaks.site-control' => __('Site Control'),
            'sites.performance' => __('Performance'),
            'sites.backups' => __('Backups'),
            'sites.uptime' => __('Uptime'),
            'sites.analytics' => __('Analytics'),
            'sites.search-console' => __('Search Console'),
            'sites.reports' => __('Reports'),
            'sites.cloudflare' => __('Cloudflare'),
            'sites.database' => __('Database'),
            'sites.settings' => __('Settings'),
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
        $crumbs[] = ['label' => __('Dashboard'), 'url' => route('dashboard')];
        $crumbs[] = ['label' => __('Sites'), 'url' => route('dashboard')];
        $pageTitle = __('Add Site');
    }
    // Clients detail
    elseif ($routeName === 'clients.show') {
        $crumbs[] = ['label' => __('Dashboard'), 'url' => route('dashboard')];
        $crumbs[] = ['label' => __('Clients'), 'url' => route('clients.index')];
    }
    // Settings pages
    elseif (str_starts_with($routeName, 'settings.')) {
        $crumbs[] = ['label' => __('Dashboard'), 'url' => route('dashboard')];

        $settingsTitle = match($routeName) {
            'settings.general' => __('General'),
            'settings.notifications' => __('Notifications'),
            'settings.profile' => __('Profile'),
            'settings.integrations' => __('Integrations'),
            'settings.report-templates' => __('Report Templates'),
            'settings.application-backup' => __('Application Backup'),
            default => $title,
        };

        if ($routeName !== 'settings.general') {
            $crumbs[] = ['label' => __('Settings'), 'url' => route('settings.general')];
        }

        $pageTitle = $settingsTitle;
    }
    // Top-level index pages: Dashboard > PageTitle
    elseif (!$isDashboard) {
        $crumbs[] = ['label' => __('Dashboard'), 'url' => route('dashboard')];
    }

    // Back button: show when not on dashboard and breadcrumbs exist
    $showBackButton = false;
    $backUrl = null;

    if (!$isDashboard && count($crumbs) > 0) {
        $showBackButton = true;
        $backUrl = $crumbs[count($crumbs) - 1]['url'];
    }
@endphp

<header class="sticky top-0 z-30 border-b bg-white dark:bg-gray-800 dark:border-gray-700 shadow-sm">
    <div class="flex h-16 items-center px-6">
        {{-- Mobile menu toggle --}}
        <button @click="mobileSidebarOpen = true" aria-label="{{ __('Open menu') }}" class="mr-3 lg:hidden text-gray-500">
            <x-icons.menu class="h-6 w-6" aria-hidden="true" />
        </button>

        {{-- Back button (shows when not on dashboard) --}}
        @if($showBackButton)
            <a href="{{ $backUrl }}" aria-label="{{ __('Go back') }}" class="mr-2 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                <x-icons.arrow-left class="h-5 w-5" aria-hidden="true" />
            </a>
        @endif

        {{-- Title + breadcrumb (left side) --}}
        <div class="flex min-w-0 flex-1 flex-col justify-center">
            @if($isDashboard)
                <h1 class="text-sm font-bold tracking-wide text-gray-900 truncate leading-tight">{{ $greeting }}</h1>
                <span class="text-xs text-gray-400 mt-0.5">{{ __('Dashboard') }}</span>
            @else
                <h1 class="text-sm font-bold tracking-wide text-gray-900 uppercase truncate leading-tight">{{ $pageTitle }}</h1>
                @if(count($crumbs) > 0)
                    <nav aria-label="{{ __('Breadcrumb') }}" class="hidden sm:flex items-center gap-1 mt-0.5">
                        @foreach($crumbs as $crumb)
                            <a href="{{ $crumb['url'] }}" class="text-xs text-gray-400 hover:text-gray-600 transition whitespace-nowrap">{{ $crumb['label'] }}</a>
                            <svg class="h-3 w-3 text-gray-300 flex-shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            @if($loop->last)
                                <span class="text-xs text-gray-400 whitespace-nowrap" aria-current="page">{{ $pageTitle }}</span>
                            @endif
                        @endforeach
                    </nav>
                @endif
            @endif
        </div>

        {{-- Right side actions --}}
        <div class="flex items-center gap-2 ml-4">
            {{-- Keyboard shortcuts hint --}}
            <button @click="$dispatch('keydown', { key: '?' })" title="{{ __('Keyboard shortcuts (?)') }}" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 transition hidden sm:block">
                <x-icons.command class="h-4 w-4" />
            </button>

            {{-- Dark mode toggle --}}
            <button @click="toggleDarkMode()" title="{{ __('Toggle dark mode') }}" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 transition">
                <x-icons.sun x-show="darkMode" x-cloak class="h-4 w-4" />
                <x-icons.moon x-show="!darkMode" class="h-4 w-4" />
            </button>

            {{-- Notifications --}}
            <livewire:components.notification-dropdown />
        </div>
    </div>
</header>
