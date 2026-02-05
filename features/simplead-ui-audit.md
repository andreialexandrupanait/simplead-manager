# SimpleAd Manager — UI Design System & Audit Checklist

> **Purpose:** Single source of truth for UI consistency across the entire platform.
> **Use:** Reference this document when building new components, reviewing PRs, or running periodic audits.
> **Stack:** Laravel 11 + Livewire 3 + Alpine.js + Tailwind CSS 3

---

## 1. Color System

### 1.1 Core Palette

| Token | Value | Usage |
|-------|-------|-------|
| `sidebar` | `#1A1A2E` | Sidebar background |
| `sidebar-hover` | `#232340` | Sidebar item hover |
| `accent` | `#8D5CF5` | Primary brand, buttons, active states, links |
| `accent-hover` | `#7C3AED` | Button hover, link hover |
| `accent-light` | `#8D5CF5/20` | Subtle accent backgrounds (badges, selected rows) |

### 1.2 Semantic Colors

| State | Background | Text | Border | Usage |
|-------|-----------|------|--------|-------|
| Success | `bg-green-100` | `text-green-700` | `border-green-200` | Up, healthy, completed, secure |
| Warning | `bg-yellow-100` | `text-yellow-700` | `border-yellow-200` | Expiring, degraded, pending |
| Danger | `bg-red-100` | `text-red-700` | `border-red-200` | Down, failed, critical, expired |
| Info | `bg-blue-100` | `text-blue-700` | `border-blue-200` | Informational notices |
| Neutral | `bg-gray-100` | `text-gray-700` | `border-gray-200` | Default, inactive, paused |
| Purple | `bg-purple-100` | `text-purple-700` | `border-purple-200` | Brand-related badges |

### 1.3 Stat Cards — Contextual Coloring

Cards that represent alerts or issues must use conditional coloring:

```
Sites Down > 0  → border-red-200 bg-red-50, icon bg-red-100 text-red-600, value text-red-600
Pending Updates  → border-orange-200 bg-orange-50, icon bg-orange-100 text-orange-600
Sites Up (ok)    → default white, icon bg-gray-100 text-gray-400
```

### 1.4 Audit Checklist — Colors

- [ ] No raw hex values in Blade templates (use Tailwind tokens or `tailwind.config.js` custom colors)
- [ ] `accent` color used consistently for primary actions (never `blue-600` or `indigo-600` as primary)
- [ ] Semantic colors follow the table above (no `red` for warnings, no `yellow` for errors)
- [ ] Dark sidebar uses only `sidebar` / `sidebar-hover` tokens
- [ ] Sidebar text: `text-white` for active, `text-white/70` for inactive, `text-white/40` for disabled
- [ ] Focus rings use `ring-accent` or `focus:ring-purple-500` consistently
- [ ] No mixed color systems (e.g., some buttons `violet`, others `purple`, others `indigo`)

---

## 2. Typography

### 2.1 Font Stack

```
font-family: 'Inter var', system-ui, -apple-system, sans-serif;
```

Configured in `tailwind.config.js` as `fontFamily.sans`.

### 2.2 Scale

| Element | Class | Size | Weight |
|---------|-------|------|--------|
| Page title | `text-2xl font-bold` | 24px | 700 |
| Section heading | `text-lg font-semibold` | 18px | 600 |
| Card title | `text-base font-semibold` | 16px | 600 |
| Body text | `text-sm` | 14px | 400 |
| Label | `text-sm font-medium` | 14px | 500 |
| Caption / helper | `text-xs` | 12px | 400 |
| Badge text | `text-xs font-medium` | 12px | 500 |
| Stat value (large) | `text-2xl font-bold` or `text-3xl font-bold` | 24-30px | 700 |
| Stat label | `text-xs text-gray-500` or `text-sm text-gray-500` | 12-14px | 400 |

### 2.3 Text Colors

| Context | Class |
|---------|-------|
| Primary text | `text-gray-900` |
| Secondary text | `text-gray-600` |
| Muted / helper | `text-gray-500` |
| Disabled | `text-gray-400` |
| Placeholder | `placeholder-gray-400` |
| Sidebar text (active) | `text-white` |
| Sidebar text (inactive) | `text-white/70` |

### 2.4 Audit Checklist — Typography

- [ ] Page titles consistently use `text-2xl font-bold text-gray-900`
- [ ] No arbitrary font sizes (e.g., `text-[15px]`) — use Tailwind scale only
- [ ] Labels always `text-sm font-medium text-gray-700`
- [ ] Helper/description text always `text-xs text-gray-500` or `text-sm text-gray-500`
- [ ] No `font-normal` on elements that should be `font-medium` (buttons, labels, nav items)
- [ ] Stat values use consistent sizing across all dashboard cards
- [ ] Headings hierarchy is logical (no `text-lg` followed by `text-xl` for a sub-section)

---

## 3. Spacing

### 3.1 Standard Spacing Scale

| Context | Value | Tailwind |
|---------|-------|----------|
| Page padding (horizontal) | 24px | `px-6` |
| Page padding (top) | 24px–32px | `pt-6` or `pt-8` |
| Section gap | 24px | `space-y-6` or `gap-6` |
| Card internal padding | 16px–24px | `p-4` or `p-6` |
| Card gap (in grid) | 16px–24px | `gap-4` or `gap-6` |
| Form field gap | 16px | `space-y-4` |
| Between label and input | 4px–8px | `mt-1` or `mt-2` |
| Inline element gap | 8px–12px | `gap-2` or `gap-3` |
| Table cell padding | 12px horizontal, 8–16px vertical | `px-3 py-2` or `px-4 py-3` |

### 3.2 Audit Checklist — Spacing

- [ ] All pages use `px-6` horizontal padding on the content area
- [ ] Card grids consistently use `gap-4` or `gap-6` (not mixed)
- [ ] Cards consistently use `p-4` or `p-6` internally (not mixed within same context)
- [ ] Form fields consistently use `space-y-4`
- [ ] No raw pixel values or `style=""` for spacing
- [ ] Sidebar items use consistent `px-3 py-2` padding
- [ ] Header height is consistently `h-16` (64px)

---

## 4. Components

### 4.1 Buttons

**Variants:**

| Variant | Classes | Usage |
|---------|---------|-------|
| Primary | `bg-accent text-white hover:bg-accent-hover` → `bg-[#8D5CF5] hover:bg-[#7C3AED]` | Main actions: Save, Create, Submit |
| Secondary | `bg-white text-gray-700 border border-gray-300 hover:bg-gray-50` | Cancel, secondary actions |
| Danger | `bg-red-600 text-white hover:bg-red-700` | Delete, destructive actions |
| Ghost | `text-gray-600 hover:text-gray-900 hover:bg-gray-100` | Tertiary actions, icon-only |

**Sizes:**

| Size | Classes |
|------|---------|
| sm | `px-3 py-1.5 text-xs` |
| md | `px-4 py-2 text-sm` (default) |
| lg | `px-6 py-3 text-base` |

**Common base:** `inline-flex items-center justify-center rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:opacity-50 disabled:cursor-not-allowed`

### 4.2 Inputs

```
rounded-lg border-gray-300 text-sm
focus:border-purple-500 focus:ring-purple-500
disabled:bg-gray-50 disabled:text-gray-500
```

Selects, textareas, and all form controls use the same border, focus, and disabled styles.

### 4.3 Cards

```
bg-white rounded-xl shadow-sm ring-1 ring-gray-950/5 p-4 (or p-6)
```

No variation — every card uses this exact pattern. No `rounded-lg` mixed with `rounded-xl`, no `shadow-md` mixed with `shadow-sm`.

### 4.4 Badges

```blade
<x-ui.badge variant="green|yellow|red|gray|purple">Text</x-ui.badge>
```

Pattern: `inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium`

Color mapping follows Section 1.2.

### 4.5 Tables

| Element | Classes |
|---------|---------|
| Container | `overflow-hidden rounded-xl ring-1 ring-gray-950/5` |
| Header row | `bg-gray-50` |
| Header cell | `px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider` |
| Body cell | `px-3 py-3 text-sm text-gray-700` (or `py-4`) |
| Row hover | `hover:bg-gray-50` |
| Row border | `border-t border-gray-100` |

### 4.6 Modals

- Backdrop: `bg-black/50`
- Container: `bg-white rounded-xl shadow-lg ring-1 ring-gray-950/5`
- Max widths: `max-w-sm`, `max-w-md`, `max-w-lg`, `max-w-xl`, `max-w-2xl`
- Transitions: `ease-out duration-100` enter, `ease-in duration-75` leave

### 4.7 Alerts / Notices

| Variant | Classes |
|---------|---------|
| Success | `bg-green-50 border-l-4 border-green-400 text-green-800` |
| Warning | `bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800` |
| Error | `bg-red-50 border-l-4 border-red-400 text-red-800` |
| Info | `bg-blue-50 border-l-4 border-blue-400 text-blue-800` |

Pattern: left border accent, light background, dark text.

### 4.8 Tooltips & Hovercards

- Tooltip: `bg-gray-900 text-white text-xs rounded-md px-2.5 py-1.5`, `z-[10000]`, positioned via `position: fixed`
- Hovercard: `bg-white rounded-lg shadow-lg ring-1 ring-gray-950/5 p-4`, `z-[9999]`, positioned via `position: fixed`
- Both use `x-teleport="body"` for correct stacking
- Both must reference `$refs.trigger` for accurate positioning (not `$el`)
- Hovercards use open/close timers (150–200ms delay)

### 4.9 Empty States

```blade
<x-ui.empty-state
    icon="..."
    title="No sites yet"
    description="Add your first site to get started."
    :action-url="route('sites.create')"
    action-label="Add Site"
/>
```

Pattern: centered icon (gray-400), title (font-semibold text-gray-900), description (text-sm text-gray-500), optional primary action button.

### 4.10 Loading / Skeleton States

- Use `animate-pulse` with `bg-gray-200 rounded` blocks
- Match the shape of the content being loaded
- Never use spinner-only states for content areas (spinners okay for inline actions)

### 4.11 Audit Checklist — Components

- [ ] All buttons use `<x-ui.button>` component, not raw `<button>` with inline classes
- [ ] Primary buttons are always purple accent (never blue, indigo, or green for primary)
- [ ] All cards use `rounded-xl shadow-sm ring-1 ring-gray-950/5` (no variations)
- [ ] All badges use `<x-ui.badge>` component with correct variant
- [ ] Tables use consistent header styling (`bg-gray-50`, uppercase, `text-xs`)
- [ ] All forms use `<x-ui.input>`, `<x-ui.select>`, etc. (no raw inputs with inline styles)
- [ ] Modals use `<x-ui.modal>` component with Alpine.js transitions
- [ ] Empty states exist for every list/table view
- [ ] Loading skeletons exist for every async-loaded section
- [ ] Tooltips and hovercards use `x-teleport="body"` and `$refs.trigger`
- [ ] No `z-index` conflicts (tooltips: 10000, hovercards: 9999, modals: 50, sidebar: 40, header: 30)

---

## 5. Layout

### 5.1 Structure

```
┌─────────────────────────────────────────────────┐
│ Sidebar (w-64, fixed, bg-sidebar, z-40)         │
│ ┌──────────┐ ┌────────────────────────────────┐ │
│ │          │ │ Header (h-16, sticky, z-30)    │ │
│ │  Logo    │ │ [search] [notifications] [user]│ │
│ │          │ ├────────────────────────────────┤ │
│ │  Nav     │ │                                │ │
│ │  Items   │ │  Content Area                  │ │
│ │          │ │  (px-6 pt-6, max-w-7xl)        │ │
│ │          │ │                                │ │
│ │  Footer  │ │                                │ │
│ └──────────┘ └────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

### 5.2 Sidebar

- Width: `w-64` (256px)
- Background: `bg-[#1A1A2E]`
- Logo area: `h-16 px-6`, text `text-lg font-bold text-white`
- Nav items: `px-3 py-2 rounded-lg text-sm font-medium`
- Active item: `bg-[#232340] text-white` with left accent bar or purple background
- Inactive item: `text-white/70 hover:text-white hover:bg-[#232340]`
- Footer: `border-t border-white/10 p-4` with avatar initials + name
- Context switching: global nav vs site-context nav via `$siteContext` middleware variable

### 5.3 Responsive

- Mobile: sidebar hidden by default, toggle via hamburger (`lg:hidden`)
- Sidebar overlay: `fixed inset-0 z-50` with backdrop
- Content shifts: `lg:pl-64`
- Grids: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4` for cards
- Tables: wrap in `overflow-x-auto` on mobile

### 5.4 Audit Checklist — Layout

- [ ] Sidebar is exactly `w-64` on all pages
- [ ] Content area uses `lg:pl-64` to offset sidebar
- [ ] Header is `sticky top-0 z-30` with `h-16` on all pages
- [ ] Page content uses consistent `px-6` horizontal padding
- [ ] Grids use responsive breakpoints (not fixed columns)
- [ ] Mobile sidebar works with Alpine.js toggle and backdrop
- [ ] No horizontal scroll on any viewport width ≥ 320px
- [ ] Page titles are positioned consistently (same vertical position across all pages)

---

## 6. Interactive States

### 6.1 Hover

| Element | Hover Effect |
|---------|-------------|
| Button (primary) | `hover:bg-accent-hover` (darker purple) |
| Button (secondary) | `hover:bg-gray-50` |
| Button (ghost) | `hover:bg-gray-100 hover:text-gray-900` |
| Card (clickable) | `hover:shadow-md` or `hover:ring-gray-300` transition |
| Table row | `hover:bg-gray-50` |
| Sidebar item | `hover:bg-sidebar-hover hover:text-white` |
| Link | `hover:text-accent-hover` or `hover:underline` |

### 6.2 Focus

All interactive elements: `focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2`

### 6.3 Disabled

`disabled:opacity-50 disabled:cursor-not-allowed`

Inputs additionally: `disabled:bg-gray-50 disabled:text-gray-500`

### 6.4 Active / Selected

- Selected tab/nav: accent color background or bottom border
- Selected table row: `bg-purple-50`
- Active filter: `bg-accent text-white` (pill shape)

### 6.5 Transitions

Default: `transition-colors duration-150` or `transition-all duration-200`
Modals/dropdowns: `ease-out duration-100` enter, `ease-in duration-75` leave

### 6.6 Audit Checklist — States

- [ ] Every clickable element has a visible hover state
- [ ] Every focusable element has a visible focus ring
- [ ] All disabled buttons/inputs show reduced opacity
- [ ] Transitions are smooth (no sudden style changes)
- [ ] Active navigation items are clearly distinguishable
- [ ] No "flash of unstyled content" on Livewire re-renders

---

## 7. Icons

### 7.1 Library

Heroicons (outline style), accessed via Blade component `<x-icon name="..." />` or inline SVG.

### 7.2 Sizes

| Context | Size | Class |
|---------|------|-------|
| Sidebar nav | 20px | `h-5 w-5` |
| Button icon (md) | 16px | `h-4 w-4` |
| Stat card icon | 20–24px | `h-5 w-5` or `h-6 w-6` |
| Status indicator (inline) | 16px | `h-4 w-4` |
| Empty state | 48px | `h-12 w-12` |
| Page header | 24px | `h-6 w-6` |

### 7.3 Audit Checklist — Icons

- [ ] Consistent icon set (all Heroicons outline, or all solid — not mixed)
- [ ] Icon sizes match context (see table above)
- [ ] Icons in buttons have `gap-2` spacing from text
- [ ] Status icons use semantic colors (green check, red x, yellow warning)
- [ ] No custom SVGs where Heroicons equivalent exists
- [ ] All icons have `aria-hidden="true"` when decorative

---

## 8. Multi-Language (RO / EN)

### 8.1 Rules

- All user-facing text goes through Laravel localization (`__()` or `@lang()`)
- Romanian diacritics (ă, â, î, ș, ț) must render correctly everywhere, including PDFs
- PDF generation uses DejaVu Sans font for full UTF-8 support

### 8.2 Audit Checklist — i18n

- [ ] No hardcoded strings in Blade templates (all use `__('key')`)
- [ ] Layout doesn't break with longer Romanian text (RO strings are ~20-30% longer than EN)
- [ ] PDF reports render diacritics correctly (ă â î ș ț Ă Â Î Ș Ț)
- [ ] Date formats respect locale (`d.m.Y` for RO, `m/d/Y` for EN)
- [ ] Number formats respect locale (`.` vs `,` for decimals)
- [ ] Button text doesn't overflow containers with longer translations

---

## 9. Z-Index Map

| Layer | Z-Index | Element |
|-------|---------|---------|
| Base content | `z-0` | Page content, cards |
| Sticky header | `z-30` | Top header bar |
| Sidebar | `z-40` | Desktop sidebar |
| Mobile sidebar overlay | `z-50` | Sidebar + backdrop on mobile |
| Dropdown menus | `z-50` | Alpine.js dropdowns |
| Modals | `z-50` | Modal + backdrop |
| Hovercards | `z-[9999]` | Teleported hovercards |
| Tooltips | `z-[10000]` | Teleported tooltips |

### Audit Checklist — Z-Index

- [ ] No arbitrary z-index values outside this map
- [ ] Tooltips always appear above hovercards
- [ ] Modals appear above sidebar
- [ ] No content clipping behind sticky header

---

## 10. Naming Conventions

### 10.1 Component Files

```
resources/views/components/ui/button.blade.php      → <x-ui.button>
resources/views/components/ui/card.blade.php         → <x-ui.card>
resources/views/components/ui/badge.blade.php        → <x-ui.badge>
resources/views/components/sidebar/global-sidebar.blade.php
resources/views/components/sidebar/site-sidebar.blade.php
```

### 10.2 CSS / Tailwind

- Use Tailwind utilities exclusively — no custom CSS classes except in `app.css` `@layer utilities`
- Exception: scrollbar styling in sidebar (`.scrollbar-thin`)
- Never use `@apply` in Blade components — use full utility classes

### 10.3 Audit Checklist — Naming

- [ ] All UI components under `components/ui/` namespace
- [ ] Livewire components follow `PascalCase` naming
- [ ] Blade components follow `kebab-case` file naming
- [ ] No duplicate components doing the same thing (e.g., two different card implementations)
- [ ] Alpine.js `x-data` objects are simple and inline (no external JS files for simple interactions)

---

## 11. Full Audit Procedure

Run this audit periodically (after each major feature) or before release.

### Step 1: Automated Scan

```bash
# Find raw hex colors in Blade templates (should use Tailwind tokens)
grep -rn '#[0-9A-Fa-f]\{6\}' resources/views/ --include="*.blade.php" | grep -v 'tailwind\|config\|SKILL'

# Find inconsistent border radius
grep -rn 'rounded-lg' resources/views/components/ui/card.blade.php  # Should be rounded-xl

# Find hardcoded strings (missing translations)
grep -rn ">[A-Z][a-z]" resources/views/ --include="*.blade.php" | grep -v '__(' | grep -v '@lang' | grep -v '{{'  | head -30

# Find inline styles (should use Tailwind)
grep -rn 'style="' resources/views/ --include="*.blade.php" | grep -v 'display: none' | grep -v 'position: fixed'

# Find z-index outside the map
grep -rn 'z-\[' resources/views/ --include="*.blade.php" | grep -v '9999\|10000'

# Find mixed accent colors (should all be purple/violet)
grep -rn 'bg-blue-600\|bg-indigo-600\|bg-blue-500\|bg-indigo-500' resources/views/ --include="*.blade.php"

# Find non-component buttons
grep -rn '<button' resources/views/livewire/ --include="*.blade.php" | grep -v 'x-ui\|x-slot' | head -20
```

### Step 2: Visual Walkthrough

Open every page and check:

1. **Dashboard** — stat cards colors, alerts grouping, activity feed, empty states
2. **Sites List** — site cards consistency, hover states, badges, responsive grid
3. **Site Detail** — all tabs (Overview, Plugins, Security, Performance, Backups, Uptime, Analytics)
4. **Settings pages** — forms, toggles, save buttons
5. **Reports** — PDF preview, diacritics, margins
6. **Authentication** — login, register, forgot password (dark theme cards)
7. **Modals** — create site, confirm delete, all modals
8. **Mobile** — hamburger menu, sidebar overlay, table scrolling, card stacking
9. **Empty states** — navigate to each view with no data

### Step 3: Cross-Page Comparison

Open two browser tabs side by side and compare:

| Compare | What to Check |
|---------|--------------|
| Dashboard vs Sites List | Page title position, spacing, card style |
| Two different site detail tabs | Header consistency, content padding, table styling |
| Create Site modal vs Edit Site | Form field sizing, button placement, spacing |
| Desktop vs Mobile (same page) | Nothing broken, proper stacking, readable text |

### Step 4: Document Findings

Use this format for each issue found:

```
[SEVERITY] [CATEGORY] — Description
  File: path/to/file.blade.php:line
  Expected: bg-accent hover:bg-accent-hover
  Found: bg-purple-600 hover:bg-purple-700
  Fix: Replace with accent token
```

Severities: `CRITICAL` (broken functionality), `MAJOR` (visual inconsistency visible to users), `MINOR` (code quality, maintainability).

---

## 12. Quick Reference Card

**Primary action** → `<x-ui.button variant="primary">` (purple)
**Card wrapper** → `rounded-xl shadow-sm ring-1 ring-gray-950/5`
**Text hierarchy** → `text-2xl` → `text-lg` → `text-base` → `text-sm` → `text-xs`
**Spacing rhythm** → `gap-6` between sections, `gap-4` within sections, `gap-2` inline
**Sidebar active** → `bg-sidebar-hover text-white`
**Focus ring** → `focus:ring-2 focus:ring-purple-500 focus:ring-offset-2`
**Status colors** → green=up/ok, yellow=warning, red=error/down, gray=neutral
**Accent everywhere** → `#8D5CF5` primary, `#7C3AED` hover
**Font** → Inter var, 14px base (`text-sm`)
**Border radius** → Cards `xl`, Buttons `lg`, Inputs `lg`, Badges `full`
