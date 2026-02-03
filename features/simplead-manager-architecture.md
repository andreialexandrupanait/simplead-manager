# SimpleAd Manager — Frontend Architecture Document

## 1. Tech Stack

| Layer | Technology | Role |
|-------|-----------|------|
| Backend | Laravel 11 | Routing, middleware, API, jobs, queues |
| Frontend interactivity | Livewire 3 | Reactive components without custom JS |
| Styling | Tailwind CSS 3 | Utility-first CSS, WPMUDEV look & feel |
| Micro-interactions | Alpine.js | Dropdowns, toggles, modals, transitions |
| Templates | Blade | Layouts, partials, reusable components |
| Auth | Laravel Breeze | Login, register, password reset, email verification |
| Icons | Heroicons / Lucide | Consistent icon set |
| Charts | ApexCharts or Chart.js | Dashboards, monitoring graphs |

---

## 2. Design Direction

The entire UI replicates the **WPMUDEV Hub** aesthetic:

- **Sidebar**: Dark (#1A1A2E or similar deep navy/dark), white text, purple accent (#8D5CF5)
- **Content area**: Light background (#F5F5F7), white cards with subtle shadows
- **Typography**: Inter or system font stack, clean and modern
- **Accent color**: Purple (#8D5CF5) for active states, buttons, links
- **Cards**: White, rounded-lg, shadow-sm, consistent padding
- **Status indicators**: Green (healthy), yellow (warning), red (critical), gray (inactive)

---

## 3. Folder Structure

```
app/
├── Http/
│   ├── Controllers/             # Thin controllers (mostly for non-Livewire pages)
│   │   ├── Auth/                # Breeze auth controllers (customized)
│   │   ├── DashboardController.php
│   │   └── ...
│   ├── Middleware/
│   │   └── SetCurrentSite.php   # Middleware to resolve current site context
│   └── Requests/                # Form request validation
│
├── Livewire/
│   ├── Dashboard/
│   │   └── GlobalDashboard.php
│   ├── Sites/
│   │   ├── SitesList.php        # Main sites listing page
│   │   ├── CreateSite.php       # Add new site wizard
│   │   └── Detail/              # Site-context pages
│   │       ├── SiteOverview.php
│   │       ├── SitePlugins.php
│   │       ├── SiteSecurity.php
│   │       ├── SitePerformance.php
│   │       ├── SiteBackups.php
│   │       ├── SiteAnalytics.php
│   │       ├── SiteUptime.php
│   │       ├── SiteUpdates.php
│   │       └── SiteSettings.php
│   ├── Uptime/
│   │   └── UptimeOverview.php   # Global uptime view
│   ├── Clients/
│   │   ├── ClientsList.php
│   │   └── ClientDetail.php
│   ├── Reports/
│   │   └── ReportsOverview.php
│   ├── Settings/
│   │   ├── GeneralSettings.php
│   │   ├── NotificationSettings.php
│   │   └── ProfileSettings.php
│   └── Components/              # Reusable Livewire sub-components
│       ├── SiteCard.php
│       ├── HealthScore.php
│       ├── StatusBadge.php
│       └── DataTable.php
│
├── Models/                      # Eloquent models (existing)
├── Services/                    # Business logic (existing)
├── Jobs/                        # Queue jobs (existing)
└── View/
    └── Components/              # Blade anonymous components
        ├── layouts/
        │   ├── app.blade.php            # Main authenticated layout
        │   └── guest.blade.php          # Auth pages layout (login, register)
        ├── sidebar/
        │   ├── global-sidebar.blade.php
        │   ├── site-sidebar.blade.php
        │   └── sidebar-item.blade.php
        ├── ui/
        │   ├── card.blade.php
        │   ├── button.blade.php
        │   ├── badge.blade.php
        │   ├── modal.blade.php
        │   ├── dropdown.blade.php
        │   ├── input.blade.php
        │   ├── select.blade.php
        │   ├── toggle.blade.php
        │   ├── alert.blade.php
        │   ├── table.blade.php
        │   ├── th.blade.php
        │   ├── td.blade.php
        │   └── empty-state.blade.php
        └── charts/
            ├── line-chart.blade.php
            └── donut-chart.blade.php

resources/
├── views/
│   ├── livewire/
│   │   ├── dashboard/
│   │   │   └── global-dashboard.blade.php
│   │   ├── sites/
│   │   │   ├── sites-list.blade.php
│   │   │   ├── create-site.blade.php
│   │   │   └── detail/
│   │   │       ├── site-overview.blade.php
│   │   │       ├── site-plugins.blade.php
│   │   │       ├── site-security.blade.php
│   │   │       ├── site-performance.blade.php
│   │   │       ├── site-backups.blade.php
│   │   │       ├── site-analytics.blade.php
│   │   │       ├── site-uptime.blade.php
│   │   │       ├── site-updates.blade.php
│   │   │       └── site-settings.blade.php
│   │   ├── uptime/
│   │   │   └── uptime-overview.blade.php
│   │   ├── clients/
│   │   │   ├── clients-list.blade.php
│   │   │   └── client-detail.blade.php
│   │   ├── reports/
│   │   │   └── reports-overview.blade.php
│   │   └── settings/
│   │       ├── general-settings.blade.php
│   │       ├── notification-settings.blade.php
│   │       └── profile-settings.blade.php
│   ├── components/              # Blade component views (mirrors app/View/Components/)
│   │   ├── layouts/
│   │   ├── sidebar/
│   │   ├── ui/
│   │   └── charts/
│   └── auth/                    # Breeze auth views (customized)
│       ├── login.blade.php
│       ├── register.blade.php
│       ├── forgot-password.blade.php
│       └── reset-password.blade.php
│
├── css/
│   └── app.css                  # Tailwind imports + custom styles
├── js/
│   └── app.js                   # Alpine.js + any custom JS
└── images/
    └── ...

public/
├── build/                       # Vite compiled assets
└── ...
```

---

## 4. Layout System

### 4.1 Main Layout (`app.blade.php`)

The authenticated layout has three zones: **sidebar**, **header**, and **content area**.

```
┌──────────────────────────────────────────────────────┐
│ ┌─────────┐ ┌──────────────────────────────────────┐ │
│ │         │ │  HEADER (search, notif, avatar)       │ │
│ │         │ ├──────────────────────────────────────┤ │
│ │ SIDEBAR │ │                                      │ │
│ │  (dark) │ │          CONTENT AREA                │ │
│ │         │ │         (light bg, white cards)       │ │
│ │         │ │                                      │ │
│ │         │ │                                      │ │
│ └─────────┘ └──────────────────────────────────────┘ │
└──────────────────────────────────────────────────────┘
```

**Layout Blade skeleton:**

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-gray-50">

    <div class="flex h-full" x-data="{ sidebarOpen: true, mobileSidebarOpen: false }">

        {{-- Mobile overlay --}}
        <div x-show="mobileSidebarOpen" x-transition.opacity
             class="fixed inset-0 z-40 bg-black/50 lg:hidden"
             @click="mobileSidebarOpen = false">
        </div>

        {{-- Sidebar --}}
        <aside class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-[#1A1A2E] transition-transform duration-200
                      lg:translate-x-0"
               :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'">

            {{-- Logo area --}}
            <div class="flex h-16 items-center px-6">
                <a href="{{ route('dashboard') }}" class="text-lg font-bold text-white">
                    SimpleAd Manager
                </a>
            </div>

            {{-- Dynamic sidebar content --}}
            <nav class="flex-1 overflow-y-auto px-3 py-4">
                @if(isset($siteContext) && $siteContext)
                    <x-sidebar.site-sidebar :site="$siteContext" />
                @else
                    <x-sidebar.global-sidebar />
                @endif
            </nav>

            {{-- Sidebar footer --}}
            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium">
                        {{ auth()->user()->initials }}
                    </div>
                    <div class="text-sm text-white/80">{{ auth()->user()->name }}</div>
                </div>
            </div>
        </aside>

        {{-- Main content --}}
        <div class="flex flex-1 flex-col lg:pl-64">

            {{-- Header --}}
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b bg-white px-6 shadow-sm">
                {{-- Mobile menu toggle --}}
                <button @click="mobileSidebarOpen = true" class="lg:hidden text-gray-500">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                {{-- Breadcrumb (optional) --}}
                @if(isset($breadcrumb))
                    <div class="hidden lg:flex items-center text-sm text-gray-500">
                        {{ $breadcrumb }}
                    </div>
                @endif

                <div class="flex-1"></div>

                {{-- Search --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </button>
                </div>

                {{-- Notifications --}}
                <button class="relative text-gray-400 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    {{-- Notification dot --}}
                    <span class="absolute -top-1 -right-1 h-2 w-2 rounded-full bg-red-500"></span>
                </button>

                {{-- Avatar dropdown --}}
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="flex items-center gap-2">
                        <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-sm font-medium">
                            {{ auth()->user()->initials }}
                        </div>
                    </button>
                    <div x-show="open" @click.away="open = false" x-transition
                         class="absolute right-0 mt-2 w-48 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5">
                        <a href="{{ route('settings.profile') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profile</a>
                        <a href="{{ route('settings.general') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Log out</button>
                        </form>
                    </div>
                </div>
            </header>

            {{-- Page content --}}
            <main class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
```

### 4.2 Guest Layout (`guest.blade.php`)

Used for authentication pages. Centered card on a dark/gradient background (WPMUDEV style).

```blade
{{-- resources/views/components/layouts/guest.blade.php --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SimpleAd Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#1A1A2E]">

    <div class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            {{-- Logo --}}
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-white">SimpleAd Manager</h1>
            </div>

            {{-- Card --}}
            <div class="rounded-xl bg-white p-8 shadow-xl">
                {{ $slot }}
            </div>
        </div>
    </div>

</body>
</html>
```

---

## 5. Sidebar System

### 5.1 Global Sidebar

Shown on all pages except when inside a specific site context.

```blade
{{-- resources/views/components/sidebar/global-sidebar.blade.php --}}
<div class="space-y-1">
    <x-sidebar.sidebar-item
        :href="route('dashboard')"
        icon="home"
        :active="request()->routeIs('dashboard')"
    >
        Dashboard
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('sites.index')"
        icon="globe"
        :active="request()->routeIs('sites.*')"
    >
        Sites
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('uptime.index')"
        icon="activity"
        :active="request()->routeIs('uptime.*')"
    >
        Uptime
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('clients.index')"
        icon="users"
        :active="request()->routeIs('clients.*')"
    >
        Clients
    </x-sidebar.sidebar-item>

    <x-sidebar.sidebar-item
        :href="route('reports.index')"
        icon="file-text"
        :active="request()->routeIs('reports.*')"
    >
        Reports
    </x-sidebar.sidebar-item>

    <div class="pt-6">
        <p class="px-3 text-xs font-semibold uppercase tracking-wider text-white/40">System</p>
        <div class="mt-2 space-y-1">
            <x-sidebar.sidebar-item
                :href="route('settings.general')"
                icon="settings"
                :active="request()->routeIs('settings.*')"
            >
                Settings
            </x-sidebar.sidebar-item>
        </div>
    </div>
</div>
```

### 5.2 Site-Context Sidebar

When a user clicks on a specific site, the sidebar transforms to show site-specific navigation with a back button.

```blade
{{-- resources/views/components/sidebar/site-sidebar.blade.php --}}
@props(['site'])

<div class="space-y-4">
    {{-- Back to sites --}}
    <a href="{{ route('sites.index') }}"
       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/60 hover:text-white hover:bg-white/5 transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        All Sites
    </a>

    {{-- Current site info --}}
    <div class="rounded-lg bg-white/5 px-3 py-3">
        <div class="flex items-center gap-3">
            <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=32"
                 alt="" class="h-6 w-6 rounded">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-white">{{ $site->name }}</p>
                <p class="truncate text-xs text-white/50">{{ $site->domain }}</p>
            </div>
        </div>
    </div>

    {{-- Site navigation --}}
    <div class="space-y-1">
        <x-sidebar.sidebar-item
            :href="route('sites.overview', $site)"
            icon="layout-dashboard"
            :active="request()->routeIs('sites.overview')"
        >
            Overview
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.plugins', $site)"
            icon="puzzle"
            :active="request()->routeIs('sites.plugins')"
        >
            Plugins & Themes
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.updates', $site)"
            icon="refresh-cw"
            :active="request()->routeIs('sites.updates')"
        >
            Updates
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.security', $site)"
            icon="shield"
            :active="request()->routeIs('sites.security')"
        >
            Security
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.performance', $site)"
            icon="zap"
            :active="request()->routeIs('sites.performance')"
        >
            Performance
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.backups', $site)"
            icon="hard-drive"
            :active="request()->routeIs('sites.backups')"
        >
            Backups
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.uptime', $site)"
            icon="activity"
            :active="request()->routeIs('sites.uptime')"
        >
            Uptime
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.analytics', $site)"
            icon="bar-chart-2"
            :active="request()->routeIs('sites.analytics')"
        >
            Analytics
        </x-sidebar.sidebar-item>

        <x-sidebar.sidebar-item
            :href="route('sites.settings', $site)"
            icon="settings"
            :active="request()->routeIs('sites.settings')"
        >
            Site Settings
        </x-sidebar.sidebar-item>
    </div>
</div>
```

### 5.3 Sidebar Item Component

```blade
{{-- resources/views/components/sidebar/sidebar-item.blade.php --}}
@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}"
   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition
          {{ $active
              ? 'bg-purple-500/20 text-white'
              : 'text-white/70 hover:text-white hover:bg-white/5' }}">

    {{-- Icon placeholder — use Lucide/Heroicons via blade-icons or inline SVGs --}}
    <x-dynamic-component :component="'icon-' . $icon" class="h-5 w-5 shrink-0" />

    <span>{{ $slot }}</span>
</a>
```

---

## 6. Routing

### 6.1 Route Definitions

```php
// routes/web.php

use App\Http\Controllers\DashboardController;
use App\Livewire\Sites;
use App\Livewire\Uptime;
use App\Livewire\Clients;
use App\Livewire\Reports;
use App\Livewire\Settings;

// Auth routes (Breeze)
require __DIR__.'/auth.php';

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/', Sites\SitesList::class)->name('dashboard');

    // Sites — global
    Route::get('/sites', Sites\SitesList::class)->name('sites.index');
    Route::get('/sites/create', Sites\CreateSite::class)->name('sites.create');

    // Sites — site-context (uses {site} parameter)
    Route::prefix('/sites/{site}')->group(function () {
        Route::get('/', Sites\Detail\SiteOverview::class)->name('sites.overview');
        Route::get('/plugins', Sites\Detail\SitePlugins::class)->name('sites.plugins');
        Route::get('/updates', Sites\Detail\SiteUpdates::class)->name('sites.updates');
        Route::get('/security', Sites\Detail\SiteSecurity::class)->name('sites.security');
        Route::get('/performance', Sites\Detail\SitePerformance::class)->name('sites.performance');
        Route::get('/backups', Sites\Detail\SiteBackups::class)->name('sites.backups');
        Route::get('/uptime', Sites\Detail\SiteUptime::class)->name('sites.uptime');
        Route::get('/analytics', Sites\Detail\SiteAnalytics::class)->name('sites.analytics');
        Route::get('/settings', Sites\Detail\SiteSettings::class)->name('sites.settings');
    });

    // Uptime — global view
    Route::get('/uptime', Uptime\UptimeOverview::class)->name('uptime.index');

    // Clients
    Route::get('/clients', Clients\ClientsList::class)->name('clients.index');
    Route::get('/clients/{client}', Clients\ClientDetail::class)->name('clients.show');

    // Reports
    Route::get('/reports', Reports\ReportsOverview::class)->name('reports.index');

    // Settings
    Route::prefix('/settings')->group(function () {
        Route::get('/', Settings\GeneralSettings::class)->name('settings.general');
        Route::get('/notifications', Settings\NotificationSettings::class)->name('settings.notification');
        Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');
    });
});
```

### 6.2 Site Context Middleware

This middleware resolves the current site and passes it to the layout so the sidebar knows which mode to show.

```php
// app/Http/Middleware/SetCurrentSite.php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;

class SetCurrentSite
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->route('site')) {
            $site = $request->route('site');

            // If route model binding gives us an ID, resolve it
            if (!$site instanceof Site) {
                $site = Site::findOrFail($site);
            }

            // Share with all views
            view()->share('siteContext', $site);

            // Also available via request
            $request->merge(['currentSite' => $site]);
        }

        return $next($request);
    }
}
```

Register in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\SetCurrentSite::class,
    ]);
})
```

---

## 7. Livewire Components — Conventions

### 7.1 Base Pattern for Site-Context Pages

All site-specific pages follow the same pattern: they receive the `$site` model and use the site-context layout.

```php
// app/Livewire/Sites/Detail/SiteOverview.php

namespace App\Livewire\Sites\Detail;

use App\Models\Site;
use Livewire\Component;

class SiteOverview extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Overview',
            ]);
    }
}
```

### 7.2 Base Pattern for Global Pages

```php
// app/Livewire/Sites/SitesList.php

namespace App\Livewire\Sites;

use App\Models\Site;
use Livewire\Component;
use Livewire\WithPagination;

class SitesList extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = 'all'; // all, healthy, warning, critical

    public function render()
    {
        $sites = Site::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->with(['client', 'latestChecks'])
            ->latest()
            ->paginate(12);

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', [
                'title' => 'Sites',
            ]);
    }
}
```

### 7.3 Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Livewire class | PascalCase | `SiteOverview.php` |
| Livewire view | kebab-case | `site-overview.blade.php` |
| Blade component | kebab-case | `<x-ui.card>` |
| Route name | dot notation | `sites.overview` |
| URL | kebab-case | `/sites/{site}/overview` |
| CSS classes | Tailwind utilities | No custom CSS unless absolutely necessary |

---

## 8. Authentication (Laravel Breeze — Customized)

### 8.1 Setup

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade

# Then customize the views to match WPMUDEV style
```

### 8.2 What Breeze Gives Us

- Login page (`/login`)
- Registration page (`/register`)
- Forgot password (`/forgot-password`)
- Reset password (`/reset-password`)
- Email verification (`/verify-email`)
- Password confirmation (`/confirm-password`)
- Profile editing (basic)

### 8.3 Customization Plan

After installing Breeze, we customize:

1. **Replace Breeze's layout** with our `guest.blade.php` (dark background, centered white card)
2. **Restyle all forms** to match WPMUDEV's clean input style (rounded inputs, purple buttons, subtle shadows)
3. **Add User model `initials` accessor** for avatar circles:

```php
// app/Models/User.php

public function getInitialsAttribute(): string
{
    $words = explode(' ', $this->name);
    return strtoupper(
        collect($words)->take(2)->map(fn ($w) => $w[0])->implode('')
    );
}
```

### 8.4 Login Page Example

```blade
{{-- resources/views/auth/login.blade.php --}}
<x-layouts.guest title="Log In">
    <h2 class="text-xl font-bold text-gray-900 mb-6">Welcome back</h2>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" name="email" :value="old('email')" required autofocus />
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <x-ui.input type="password" name="password" required />
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-sm text-purple-600 hover:text-purple-700">
                Forgot password?
            </a>
        </div>

        <x-ui.button type="submit" class="w-full">Log In</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        Don't have an account?
        <a href="{{ route('register') }}" class="text-purple-600 hover:text-purple-700 font-medium">Sign up</a>
    </p>
</x-layouts.guest>
```

---

## 9. UI Component Library (Design System)

### 9.1 Card

```blade
{{-- resources/views/components/ui/card.blade.php --}}
@props(['padding' => true])

<div {{ $attributes->merge([
    'class' => 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 ' . ($padding ? 'p-6' : '')
]) }}>
    {{ $slot }}
</div>
```

Usage: `<x-ui.card>Content here</x-ui.card>`

### 9.2 Button

```blade
{{-- resources/views/components/ui/button.blade.php --}}
@props([
    'variant' => 'primary',    // primary, secondary, danger, ghost
    'size' => 'md',            // sm, md, lg
])

@php
$classes = match($variant) {
    'primary'   => 'bg-purple-600 text-white hover:bg-purple-700 focus:ring-purple-500',
    'secondary' => 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-500',
    'danger'    => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    'ghost'     => 'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-500',
};

$sizes = match($size) {
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-6 py-3 text-base',
};
@endphp

<button {{ $attributes->merge([
    'class' => "inline-flex items-center justify-center gap-2 rounded-lg font-medium transition
                focus:outline-none focus:ring-2 focus:ring-offset-2
                disabled:opacity-50 disabled:cursor-not-allowed
                {$classes} {$sizes}"
]) }}>
    {{ $slot }}
</button>
```

### 9.3 Input

```blade
{{-- resources/views/components/ui/input.blade.php --}}
@props([])

<input {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                shadow-sm transition
                placeholder:text-gray-400
                focus:border-purple-500 focus:ring-1 focus:ring-purple-500
                disabled:bg-gray-50 disabled:text-gray-500'
]) }}>
```

### 9.4 Badge / Status

```blade
{{-- resources/views/components/ui/badge.blade.php --}}
@props([
    'variant' => 'gray',    // green, yellow, red, gray, purple
])

@php
$classes = match($variant) {
    'green'  => 'bg-green-100 text-green-700',
    'yellow' => 'bg-yellow-100 text-yellow-700',
    'red'    => 'bg-red-100 text-red-700',
    'gray'   => 'bg-gray-100 text-gray-700',
    'purple' => 'bg-purple-100 text-purple-700',
};
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"
]) }}>
    {{ $slot }}
</span>
```

### 9.5 Modal (Alpine.js)

```blade
{{-- resources/views/components/ui/modal.blade.php --}}
@props(['name', 'maxWidth' => 'lg'])

@php
$maxWidthClass = match($maxWidth) {
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
};
@endphp

<div x-data="{ open: false }"
     x-on:open-modal-{{ $name }}.window="open = true"
     x-on:close-modal-{{ $name }}.window="open = false"
     x-show="open"
     x-transition
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display: none;">

    {{-- Backdrop --}}
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" @click="open = false"></div>

    {{-- Content --}}
    <div x-show="open" x-transition
         class="relative w-full {{ $maxWidthClass }} rounded-xl bg-white p-6 shadow-xl">
        {{ $slot }}
    </div>
</div>
```

Usage:
```blade
<button @click="$dispatch('open-modal-confirm')">Delete</button>
<x-ui.modal name="confirm">
    <h3>Are you sure?</h3>
    <x-ui.button variant="danger">Confirm</x-ui.button>
</x-ui.modal>
```

### 9.6 Table

```blade
{{-- resources/views/components/ui/table.blade.php --}}
<div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5">
    <table class="min-w-full divide-y divide-gray-200">
        @if(isset($head))
            <thead class="bg-gray-50">
                <tr>{{ $head }}</tr>
            </thead>
        @endif
        <tbody class="divide-y divide-gray-200">
            {{ $slot }}
        </tbody>
    </table>
</div>

{{-- resources/views/components/ui/th.blade.php --}}
@props([])
<th {{ $attributes->merge(['class' => 'px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500']) }}>
    {{ $slot }}
</th>

{{-- resources/views/components/ui/td.blade.php --}}
@props([])
<td {{ $attributes->merge(['class' => 'whitespace-nowrap px-4 py-3 text-sm text-gray-700']) }}>
    {{ $slot }}
</td>
```

### 9.7 Empty State

```blade
{{-- resources/views/components/ui/empty-state.blade.php --}}
@props(['title', 'description' => null, 'icon' => 'inbox'])

<div class="flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-4 rounded-full bg-gray-100 p-4">
        <x-dynamic-component :component="'icon-' . $icon" class="h-8 w-8 text-gray-400" />
    </div>
    <h3 class="text-sm font-medium text-gray-900">{{ $title }}</h3>
    @if($description)
        <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
    @endif
    @if(isset($action))
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
```

---

## 10. Site Card Component (Sites Listing)

This is the main card shown on the Sites page, replicating the WPMUDEV site card style.

```blade
{{-- resources/views/livewire/sites/partials/site-card.blade.php --}}
<div class="group rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 hover:shadow-md transition">
    <div class="flex items-start justify-between">
        {{-- Site info --}}
        <a href="{{ route('sites.overview', $site) }}" class="flex items-center gap-3 min-w-0">
            <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=32"
                 alt="" class="h-8 w-8 rounded-lg ring-1 ring-gray-200">
            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-gray-900 group-hover:text-purple-600 transition">
                    {{ $site->name }}
                </h3>
                <p class="truncate text-xs text-gray-500">{{ $site->domain }}</p>
            </div>
        </a>

        {{-- Health score --}}
        <div class="flex items-center gap-1.5">
            @php
                $score = $site->health_score;
                $color = $score >= 90 ? 'text-green-500' : ($score >= 70 ? 'text-yellow-500' : 'text-red-500');
            @endphp
            <span class="text-lg font-bold {{ $color }}">{{ $score }}</span>
        </div>
    </div>

    {{-- Status icons row --}}
    <div class="mt-4 flex items-center gap-4 border-t pt-4 text-xs text-gray-500">
        {{-- Uptime --}}
        <div class="flex items-center gap-1"
             title="Uptime: {{ $site->uptime_percentage ?? 'N/A' }}%">
            <span class="h-2 w-2 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
            {{ $site->uptime_percentage ?? '—' }}%
        </div>

        {{-- SSL --}}
        <div class="flex items-center gap-1"
             title="SSL expires: {{ $site->ssl_expiry?->format('M d, Y') ?? 'N/A' }}">
            <svg class="h-3.5 w-3.5 {{ $site->ssl_ok ? 'text-green-500' : 'text-red-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>

        {{-- Updates available --}}
        @if($site->pending_updates_count > 0)
            <div class="flex items-center gap-1 text-yellow-600">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                {{ $site->pending_updates_count }}
            </div>
        @endif

        {{-- Backup status --}}
        <div class="flex items-center gap-1"
             title="Last backup: {{ $site->last_backup_at?->diffForHumans() ?? 'Never' }}">
            <svg class="h-3.5 w-3.5 {{ $site->backup_ok ? 'text-green-500' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
            </svg>
        </div>

        <div class="flex-1"></div>

        {{-- Client tag --}}
        @if($site->client)
            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                {{ $site->client->name }}
            </span>
        @endif
    </div>
</div>
```

---

## 11. Tailwind Configuration

```js
// tailwind.config.js

const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './app/View/**/*.php',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter var', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                sidebar: {
                    DEFAULT: '#1A1A2E',
                    hover: '#232340',
                },
                accent: {
                    DEFAULT: '#8D5CF5',
                    hover: '#7C3AED',
                    light: '#8D5CF5/20',
                },
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
};
```

---

## 12. Asset Pipeline (Vite)

```js
// vite.config.js

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});
```

```css
/* resources/css/app.css */
@tailwind base;
@tailwind components;
@tailwind utilities;

/* Custom scrollbar for sidebar */
@layer utilities {
    .scrollbar-thin {
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,0.2) transparent;
    }
    .scrollbar-thin::-webkit-scrollbar {
        width: 4px;
    }
    .scrollbar-thin::-webkit-scrollbar-track {
        background: transparent;
    }
    .scrollbar-thin::-webkit-scrollbar-thumb {
        background-color: rgba(255,255,255,0.2);
        border-radius: 4px;
    }
}
```

```js
// resources/js/app.js
import './bootstrap';

// Alpine.js comes with Livewire 3 — no manual import needed
// Add custom Alpine plugins or stores here if needed
```

---

## 13. Installation Steps (Quick Start)

```bash
# 1. Create Laravel project
composer create-project laravel/laravel simplead-manager
cd simplead-manager

# 2. Install dependencies
composer require livewire/livewire
composer require laravel/breeze --dev
php artisan breeze:install blade

# 3. Install frontend
npm install
npm install @tailwindcss/forms

# 4. Configure database (.env)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=simplead
DB_USERNAME=simplead
DB_PASSWORD=secret

# 5. Run migrations
php artisan migrate

# 6. Install icon package (optional — or use inline SVGs)
composer require blade-ui-kit/blade-heroicons

# 7. Build assets
npm run dev   # development
npm run build # production

# 8. Serve
php artisan serve
```

---

## 14. Summary — Architecture Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| No Filament | ✅ | Full UI control, pixel-perfect WPMUDEV match |
| Livewire 3 | ✅ | Reactive components without SPA complexity |
| Alpine.js | ✅ | Built into Livewire, handles micro-interactions |
| Laravel Breeze | ✅ | Simple auth with Blade views, easy to customize |
| Blade components | ✅ | Reusable UI primitives (card, button, badge, etc.) |
| Tailwind only | ✅ | No custom CSS files per component, consistent styling |
| Route model binding | ✅ | Clean URLs: `/sites/{site}/overview` |
| Dynamic sidebar | ✅ | Global ↔ site-context switching via middleware |
| PostgreSQL | ✅ | Better JSON support, better performance for analytics queries |
| Redis | ✅ | Queues, caching, real-time features |
