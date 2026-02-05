# Sidebar Restructure Task

## Context

We have a Laravel/Livewire application with a sidebar navigation. Currently, all menu items are listed flat without visual grouping, making it feel chaotic. We want to restructure the sidebar into clearly defined sections with visual separators and section titles.

## Files to Modify

- `resources/views/components/sidebar/global-sidebar.blade.php`
- `resources/views/components/sidebar/site-sidebar.blade.php`

## File to Create

- `resources/views/components/sidebar/sidebar-section.blade.php`

---

## Requirements

### 1. Create `sidebar-section.blade.php` Component

Create a new Blade component that renders a section header with a title and visual separator.

**Props:**
- `title` (string) - The section label (e.g., "Monitoring", "Management")

**Styling requirements:**
- Border-top with subtle white line (`border-white/10`) for visual separation
- Padding-top for spacing from previous section (`pt-6`)
- Title text: extra small (`text-[10px]`), uppercase, font-semibold, wide letter-spacing (`tracking-widest`), muted color (`text-white/40`)
- Horizontal padding matching sidebar items (`px-3`)
- When sidebar is collapsed (using Alpine.js `sidebarOpen` variable), the title should hide with transition (`lg:opacity-0 lg:w-0 lg:overflow-hidden`)

**Example structure:**
```html
<div class="pt-6 border-t border-white/10">
    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-white/40 transition-all duration-300"
       :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
        {{ $title }}
    </p>
    <div class="space-y-1">
        {{ $slot }}
    </div>
</div>
```

---

### 2. Restructure `global-sidebar.blade.php`

Reorganize the existing sidebar items into the following sections:

**Standalone (no section header):**
- Dashboard

**MONITORING section:**
- Uptime
- Performance
- Errors

**MANAGEMENT section:**
- Backups
- Updates
- Clients
- Reports
- Activity
- Status Pages

**SYSTEM section:**
- Settings

**Expected structure:**
```html
<div class="space-y-1">
    {{-- Standalone --}}
    <x-sidebar.sidebar-item :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">
        Dashboard
    </x-sidebar.sidebar-item>

    {{-- Monitoring Section --}}
    <x-sidebar.sidebar-section title="Monitoring">
        <x-sidebar.sidebar-item :href="route('uptime.index')" icon="activity" :active="request()->routeIs('uptime.*')">
            Uptime
        </x-sidebar.sidebar-item>
        {{-- ... other items --}}
    </x-sidebar.sidebar-section>

    {{-- Management Section --}}
    <x-sidebar.sidebar-section title="Management">
        {{-- ... items --}}
    </x-sidebar.sidebar-section>

    {{-- System Section --}}
    <x-sidebar.sidebar-section title="System">
        {{-- ... items --}}
    </x-sidebar.sidebar-section>
</div>
```

---

### 3. Restructure `site-sidebar.blade.php`

Keep the existing "Back to sites" link and site info card at the top. Reorganize the navigation items into:

**Standalone (no section header):**
- Overview

**SECURITY section:**
- Security
- Firewall
- Audit Log

**MAINTENANCE section:**
- Updates
- Backups
- Performance
- Uptime
- Cron Jobs
- Maintenance

**CONTENT section:**
- Plugins & Themes
- Database
- Links

**INTEGRATIONS section:**
- DNS
- Cloudflare
- Analytics
- Search Console

**REPORTS section:**
- Errors
- Reports

**SETTINGS section:**
- Site Settings

---

## Important Notes

1. **Preserve all existing functionality:**
   - Active states on links must continue working
   - Tooltips when sidebar is collapsed must still function
   - All routes and icons remain unchanged

2. **Maintain responsive behavior:**
   - Section titles must hide when sidebar is collapsed on desktop
   - Transitions should be smooth (use `transition-all duration-300`)

3. **Visual hierarchy:**
   - First item (Dashboard/Overview) has no section - it's the primary entry point
   - Section borders create clear visual breaks
   - Consistent spacing throughout

4. **Remove the old System section** from `global-sidebar.blade.php` that was using a different approach with `pt-6` and conditional visibility.
