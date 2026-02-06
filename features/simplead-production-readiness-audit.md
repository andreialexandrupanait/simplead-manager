# SimpleAd Manager — Production Readiness Audit & Refactoring

## Your Role

You are performing a comprehensive code audit on SimpleAd Manager, a Laravel 11 + Livewire 3 + Alpine.js + Tailwind CSS platform. The goal is to make this codebase **production-ready** by eliminating waste, extracting reusable components, and enforcing consistency.

**DO NOT skip steps. DO NOT assume things are fine without checking. Be thorough.**

---

## Phase 1: Discovery — Map the Codebase

Before changing anything, build a complete picture. Run these commands and save the output to `/tmp/audit-report.md`.

### 1.1 File Inventory

```bash
# All Blade views
find resources/views -name "*.blade.php" | sort > /tmp/blade-files.txt

# All Livewire components (PHP)
find app/Livewire -name "*.php" | sort > /tmp/livewire-components.txt

# All Blade UI components
find resources/views/components -name "*.blade.php" | sort > /tmp/ui-components.txt

# All Models
find app/Models -name "*.php" | sort > /tmp/models.txt

# All Services
find app/Services -name "*.php" | sort > /tmp/services.txt

# All routes
php artisan route:list --columns=method,uri,name,action 2>/dev/null > /tmp/routes.txt

# All registered Livewire components
grep -r "class.*extends.*Component" app/Livewire/ | sort > /tmp/livewire-classes.txt
```

### 1.2 File Size Analysis (find bloated files)

```bash
# Blade files over 200 lines (candidates for splitting)
find resources/views -name "*.blade.php" -exec wc -l {} + | sort -rn | head -30 > /tmp/large-blade-files.txt

# Livewire components over 150 lines (candidates for splitting)
find app/Livewire -name "*.php" -exec wc -l {} + | sort -rn | head -20 > /tmp/large-livewire-files.txt
```

---

## Phase 2: Dead Code Detection

### 2.1 Unused Blade Views

```bash
# Find all Blade view references in the codebase
grep -roh "view('[^']*'" app/ resources/views/ routes/ --include="*.php" --include="*.blade.php" | \
  sed "s/view('//;s/'$//" | sort -u > /tmp/referenced-views.txt

# Find all Blade files and convert to dot-notation
find resources/views -name "*.blade.php" | \
  sed 's|resources/views/||;s|/|.|g;s|\.blade\.php||' | sort > /tmp/all-views.txt

# Diff to find orphaned views
comm -23 /tmp/all-views.txt /tmp/referenced-views.txt > /tmp/orphaned-views.txt
```

**Also check for:**
- Livewire render() methods pointing to non-existent views
- Views referencing non-existent components
- Components that are never used anywhere

### 2.2 Unused Livewire Components

```bash
# Find all Livewire component references in Blade
grep -roh "livewire:[a-z0-9._-]*\|<livewire:[a-z0-9._-]*" resources/views/ | sort -u > /tmp/used-livewire.txt

# Cross-reference with existing component files
# Check which Livewire classes have no route AND no Blade reference
```

### 2.3 Unused Models & Services

```bash
# Find all Model class names
grep -roh "class [A-Z][a-zA-Z]* extends Model" app/Models/ | awk '{print $2}' > /tmp/model-classes.txt

# Check usage of each model
for model in $(cat /tmp/model-classes.txt); do
  count=$(grep -rl "$model" app/ resources/ routes/ --include="*.php" --include="*.blade.php" | grep -v "app/Models/$model.php" | wc -l)
  echo "$count references: $model"
done | sort -n > /tmp/model-usage.txt
```

### 2.4 Unused Routes

```bash
# Named routes that are never referenced
php artisan route:list --columns=name 2>/dev/null | grep -v "^$" | while read name; do
  if [ ! -z "$name" ]; then
    count=$(grep -rl "route('$name'" app/ resources/ --include="*.php" --include="*.blade.php" 2>/dev/null | wc -l)
    if [ "$count" -eq "0" ]; then
      echo "UNUSED ROUTE: $name"
    fi
  fi
done > /tmp/unused-routes.txt
```

### 2.5 Leftover Filament Code

Since we migrated from Filament, check for remnants:

```bash
# Any Filament references still in codebase
grep -rl "Filament\|filament" app/ config/ resources/ routes/ --include="*.php" --include="*.blade.php" 2>/dev/null > /tmp/filament-remnants.txt

# Filament config files
ls config/filament*.php 2>/dev/null

# Filament service provider
grep -l "FilamentServiceProvider\|FilamentPanelProvider" app/Providers/ 2>/dev/null
```

**Action:** Remove ALL Filament remnants. We don't use it anymore.

---

## Phase 3: Duplicate Code Detection

### 3.1 Duplicate HTML/Blade Patterns

Search for repeated UI patterns that should be extracted into components:

```bash
# Search bars — should be ONE component
grep -rl "wire:model.*search\|x-model.*search" resources/views/ --include="*.blade.php" > /tmp/search-patterns.txt

# Status filter tabs/pills — should be ONE component
grep -rl "filter.*all.*active\|statusFilter\|wire:click.*filter" resources/views/ --include="*.blade.php" > /tmp/filter-patterns.txt

# Sort dropdowns — should be ONE component
grep -rl "sortBy\|sortDirection\|sort.*asc\|sort.*desc" resources/views/ --include="*.blade.php" > /tmp/sort-patterns.txt

# Pagination — should use ONE consistent approach
grep -rl "->links()\|hasPages\|WithPagination" resources/views/ app/Livewire/ --include="*.php" --include="*.blade.php" > /tmp/pagination-patterns.txt

# Delete confirmation modals — should be ONE component
grep -rl "delete.*confirm\|confirm.*delete\|Are you sure" resources/views/ --include="*.blade.php" > /tmp/delete-modals.txt

# Empty states — should use x-ui.empty-state
grep -rl "No.*yet\|no.*found\|empty.*state" resources/views/ --include="*.blade.php" > /tmp/empty-states.txt

# Loading states / skeletons
grep -rl "wire:loading\|animate-pulse\|skeleton" resources/views/ --include="*.blade.php" > /tmp/loading-states.txt

# Action dropdown menus (three dots / kebab)
grep -rl "dropdown\|three.*dot\|kebab\|actions.*menu\|ellipsis" resources/views/ --include="*.blade.php" > /tmp/action-dropdowns.txt
```

**For each pattern found in multiple files:**
1. Compare the actual code side by side
2. Identify if they're doing the same thing with minor variations
3. Extract into a shared Blade component

### 3.2 Duplicate Livewire Logic

```bash
# Livewire components with search + filter + sort + pagination
# These should use a shared trait
grep -l "WithPagination" app/Livewire/ -r --include="*.php" > /tmp/paginated-components.txt

# Check if they all implement search/filter/sort the same way
for file in $(cat /tmp/paginated-components.txt); do
  echo "=== $file ==="
  grep -E "public.*search|public.*filter|public.*sort|updatedSearch|updatedFilter" "$file"
  echo ""
done > /tmp/searchable-components-comparison.txt
```

### 3.3 Duplicate Tailwind Class Patterns

```bash
# Find repeated long class strings (potential component extraction)
grep -roh 'class="[^"]\{80,\}"' resources/views/ --include="*.blade.php" | \
  sort | uniq -c | sort -rn | head -20 > /tmp/repeated-classes.txt
```

---

## Phase 4: Component Extraction Checklist

Based on Phase 3 findings, these are the components that SHOULD exist as shared Blade components. Check if they exist and if they're actually used everywhere:

### 4.1 Must-Have Shared Components

| Component | File | Used For |
|-----------|------|----------|
| `x-ui.search-input` | `components/ui/search-input.blade.php` | Debounced search with icon, clear button |
| `x-ui.filter-tabs` | `components/ui/filter-tabs.blade.php` | Status/category filter pills with counts |
| `x-ui.sort-dropdown` | `components/ui/sort-dropdown.blade.php` | Column sorting selector |
| `x-ui.data-table` | `components/ui/data-table.blade.php` | Table with header, body, pagination |
| `x-ui.confirm-modal` | `components/ui/confirm-modal.blade.php` | Delete/destructive action confirmation |
| `x-ui.empty-state` | `components/ui/empty-state.blade.php` | No data placeholder with icon + CTA |
| `x-ui.page-header` | `components/ui/page-header.blade.php` | Page title + description + action buttons |
| `x-ui.stats-card` | `components/ui/stats-card.blade.php` | Stat number + label + trend indicator |
| `x-ui.status-badge` | `components/ui/status-badge.blade.php` | Colored status pill (active, down, warning, etc.) |
| `x-ui.status-dot` | `components/ui/status-dot.blade.php` | Small colored dot for inline status |
| `x-ui.avatar` | `components/ui/avatar.blade.php` | User/client avatar with initials fallback |
| `x-ui.dropdown-menu` | `components/ui/dropdown-menu.blade.php` | Action dropdown (three dots / kebab) |
| `x-ui.loading-skeleton` | `components/ui/loading-skeleton.blade.php` | Skeleton loader for async content |
| `x-ui.tooltip` | `components/ui/tooltip.blade.php` | Consistent tooltip with positioning |
| `x-ui.hovercard` | `components/ui/hovercard.blade.php` | Rich hover popover for quick info |
| `x-ui.toggle` | `components/ui/toggle.blade.php` | On/off switch |
| `x-ui.alert` | `components/ui/alert.blade.php` | Info/success/warning/error alert banner |
| `x-ui.breadcrumbs` | `components/ui/breadcrumbs.blade.php` | Navigation breadcrumb trail |

**Audit steps for each:**
1. Does the component file exist?
2. Is it actually used? (`grep -rl "x-ui.component-name" resources/views/`)
3. Are there places that SHOULD use it but don't (inline HTML instead)?
4. Is the API consistent (same prop names, same variants)?

### 4.2 Shared Livewire Trait

Create a `HasTableFeatures` trait for Livewire components that have lists:

```php
// app/Livewire/Traits/HasTableFeatures.php

trait HasTableFeatures
{
    use WithPagination;
    
    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 25;
    
    public function updatedSearch(): void
    {
        $this->resetPage();
    }
    
    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }
}
```

**Check:** How many Livewire components currently implement search/sort/pagination manually? List them and refactor to use this trait.

---

## Phase 5: UX Consistency Audit

### 5.1 Visual Consistency Checks

Go through EVERY page in the app and verify:

```bash
# Page title format — should be consistent across all pages
grep -rn "<h1\|<h2.*page.*title\|text-2xl.*font-bold\|text-xl.*font-semibold" resources/views/livewire/ --include="*.blade.php" > /tmp/page-titles.txt
```

**Check manually:**
- [ ] Do ALL pages have a page header in the same position?
- [ ] Do ALL page headers use the same typography (size, weight, color)?
- [ ] Do ALL pages with actions have the primary button in the same position (top right)?
- [ ] Do ALL list pages have the same layout structure (header → filters → table → pagination)?
- [ ] Do ALL form pages have the same layout (card wrapper → form sections → action buttons at bottom)?
- [ ] Do ALL detail pages use consistent section organization?

### 5.2 Color Consistency

```bash
# All purple/violet/accent color usages — should all use the same shade
grep -roh "purple-[0-9]*\|violet-[0-9]*\|#8D5CF5\|#7C3AED\|accent-[0-9]*" resources/views/ --include="*.blade.php" | sort | uniq -c | sort -rn > /tmp/accent-colors.txt

# All green/success color usages
grep -roh "green-[0-9]*\|emerald-[0-9]*" resources/views/ --include="*.blade.php" | sort | uniq -c | sort -rn > /tmp/success-colors.txt

# All red/error color usages
grep -roh "red-[0-9]*\|rose-[0-9]*" resources/views/ --include="*.blade.php" | sort | uniq -c | sort -rn > /tmp/error-colors.txt

# All yellow/warning color usages
grep -roh "yellow-[0-9]*\|amber-[0-9]*\|orange-[0-9]*" resources/views/ --include="*.blade.php" | sort | uniq -c | sort -rn > /tmp/warning-colors.txt
```

**Expected result:** Each semantic color should use ONE shade consistently:
- Primary/accent: `purple-600` / `#8D5CF5` (light mode), `purple-500` / `#8D5CF5` (dark)
- Success: `green-600` (light), `green-500` (dark)
- Warning: `yellow-600` or `amber-500`
- Error: `red-600` (light), `red-500` (dark)
- Neutral: `gray-*` (no blue-gray, zinc, slate mixing)

**If you find inconsistencies (e.g., some places use `green-500` and others `emerald-600` for success), standardize them ALL.**

### 5.3 Spacing & Layout Consistency

```bash
# Content area padding
grep -rn "px-[0-9]\|py-[0-9]\|p-[0-9]" resources/views/livewire/ --include="*.blade.php" | \
  grep -v "components" > /tmp/content-padding.txt

# Card padding
grep -rn "x-ui.card\|rounded-xl.*shadow" resources/views/ --include="*.blade.php" > /tmp/card-usage.txt

# Gap between sections
grep -rn "space-y-\|gap-\|mb-\|mt-" resources/views/livewire/ --include="*.blade.php" > /tmp/spacing.txt
```

**Expected patterns:**
- Page content: `px-6 py-6` or `p-6`
- Between major sections: `space-y-6`
- Within card content: `p-5` or `p-6`
- Between card items: `space-y-4` or `gap-4`
- Between inline items: `gap-2` or `gap-3`

### 5.4 Button Consistency

```bash
# All button usages
grep -rn "x-ui.button\|btn-\|<button" resources/views/ --include="*.blade.php" > /tmp/button-usage.txt
```

**Check:**
- [ ] Primary actions always use `<x-ui.button variant="primary">` (purple)
- [ ] Secondary actions use `<x-ui.button variant="secondary">` (gray outline)
- [ ] Destructive actions use `<x-ui.button variant="danger">` (red)
- [ ] No raw `<button>` tags with inline Tailwind that should be `<x-ui.button>`
- [ ] Button sizes are consistent per context (sm in tables, default in forms, lg for page CTAs)

### 5.5 Form Consistency

```bash
# All form input patterns
grep -rn "x-ui.input\|<input\|wire:model" resources/views/ --include="*.blade.php" > /tmp/form-inputs.txt
```

**Check:**
- [ ] All inputs use `<x-ui.input>` component (no raw `<input>` tags)
- [ ] All selects use `<x-ui.select>` component
- [ ] Labels are consistently positioned (above input, same font size)
- [ ] Error messages display consistently (`@error` blocks with same styling)
- [ ] Required field indicators are consistent (asterisk or text)
- [ ] Form sections use consistent grouping (same card wrapper, same heading style)

### 5.6 Table Consistency

```bash
# All table implementations
grep -rn "x-ui.table\|<table\|<thead\|<th\|<td" resources/views/ --include="*.blade.php" > /tmp/table-usage.txt
```

**Check:**
- [ ] All tables use `<x-ui.table>` with `<x-ui.th>` and `<x-ui.td>` (no raw tables)
- [ ] Table header background is consistent (same gray shade)
- [ ] Column alignment is consistent (text left, numbers right)
- [ ] Action columns are always last and right-aligned
- [ ] Row hover state is consistent
- [ ] Responsive behavior: all tables wrap in `overflow-x-auto` on mobile

### 5.7 Toast/Notification Consistency

```bash
# All notification/toast dispatches
grep -rn "dispatch.*notify\|session.*flash\|dispatch.*toast\|$this->dispatch" app/Livewire/ --include="*.php" > /tmp/notifications.txt
```

**Check:**
- [ ] Success messages use consistent format and color
- [ ] Error messages use consistent format
- [ ] All create/update/delete actions show appropriate feedback
- [ ] Toast auto-dismiss timing is consistent

---

## Phase 6: Performance & Code Quality

### 6.1 N+1 Query Detection

```bash
# Livewire components accessing relationships in render()
# Look for patterns like $site->client->name or $monitor->site->domain
grep -rn "->client\|->site\|->monitors\|->checks\|->incidents" app/Livewire/ --include="*.php" > /tmp/relationship-access.txt

# Check if corresponding queries use eager loading
grep -rn "with(\|::with(" app/Livewire/ --include="*.php" > /tmp/eager-loading.txt
```

### 6.2 Missing Indexes

```bash
# Check all migrations for foreign keys without indexes
grep -rn "->foreignId\|->foreign(" database/migrations/ --include="*.php" > /tmp/foreign-keys.txt

# Check for columns commonly used in WHERE clauses that might need indexes
grep -rn "->where(\|->whereIn(\|->orderBy(" app/ --include="*.php" > /tmp/query-columns.txt
```

### 6.3 Unused Dependencies

```bash
# Check composer.json for packages not referenced in code
composer show --direct 2>/dev/null | awk '{print $1}' > /tmp/installed-packages.txt

# Common ones to check:
grep -rl "filament" vendor/ app/ config/ 2>/dev/null | head -5  # Should be 0 if removed
```

### 6.4 Environment-Specific Code

```bash
# Debug/development code left in
grep -rn "dd(\|dump(\|ray(\|Log::debug\|console\.log" app/ resources/ --include="*.php" --include="*.blade.php" --include="*.js" > /tmp/debug-code.txt

# TODO/FIXME/HACK comments
grep -rn "TODO\|FIXME\|HACK\|XXX\|TEMP" app/ resources/ --include="*.php" --include="*.blade.php" > /tmp/todo-comments.txt
```

---

## Phase 7: Refactoring Execution

After completing Phases 1-6 and documenting all findings in `/tmp/audit-report.md`, execute refactoring in this order:

### Step 1: Remove Dead Code
- Delete unused Blade views
- Delete unused Livewire components
- Delete unused Models and Services
- Remove Filament remnants
- Remove debug code
- Clean unused routes

### Step 2: Extract Shared Components
For each duplicated pattern found in Phase 3:
1. Create the shared Blade component with clear props
2. Replace ALL instances across the codebase
3. Verify nothing broke

### Step 3: Create HasTableFeatures Trait
1. Create the trait
2. Refactor each Livewire list component to use it
3. Ensure all list pages work identically

### Step 4: Standardize Colors
1. Audit the Tailwind config for custom colors
2. Replace all inconsistent color references
3. Ensure semantic colors are used everywhere (not arbitrary colors)

### Step 5: Standardize Layout Patterns
1. Ensure every page follows the correct layout template
2. Ensure page headers, content padding, and section spacing are identical
3. Fix any responsive issues found

### Step 6: Form Standardization
1. Replace all raw `<input>`, `<select>`, `<button>` with components
2. Ensure error display is consistent
3. Ensure label positioning is consistent

---

## Phase 8: Final Verification

After all refactoring:

```bash
# Run all tests
php artisan test

# Check for PHP errors
php artisan route:list 2>&1 | grep -i error

# Check Blade compilation
php artisan view:cache 2>&1 | grep -i error

# Asset compilation
npm run build 2>&1 | grep -i error

# Check for missing translations
grep -roh "__('[^']*'" resources/views/ app/ --include="*.php" --include="*.blade.php" | sort -u > /tmp/all-translations.txt
```

### Final Report Format

Create `/tmp/final-audit-report.md` with:

```markdown
# SimpleAd Manager — Audit Report

## Summary
- Files removed: X
- Components extracted: X  
- Components standardized: X
- Color inconsistencies fixed: X
- Filament remnants removed: X
- Debug code removed: X
- N+1 queries fixed: X

## Dead Code Removed
| File | Reason |
|------|--------|
| ... | ... |

## Components Extracted
| Component | Replaces | Used In |
|-----------|----------|---------|
| x-ui.search-input | Inline search in 5 files | SitesList, ClientsList, ... |

## Inconsistencies Fixed
| Issue | Before | After | Files Affected |
|-------|--------|-------|----------------|
| Accent color | mix of purple-500/600/700 | purple-600 everywhere | 12 files |

## Remaining TODOs
- [ ] ...
```

---

## Critical Rules

1. **NEVER delete a file without first confirming it's truly unused** — check routes, Blade references, Livewire mounts, event listeners
2. **When extracting components, preserve ALL existing functionality** — don't simplify away features during refactoring
3. **Make atomic commits** — one logical change per commit (e.g., "Extract x-ui.search-input component", "Remove unused Filament code")
4. **Test after EVERY change** — don't batch refactoring without verification
5. **When in doubt about a pattern, check how it's done in the Sites list page** — that's the reference implementation
6. **ALL shared components must support dark mode** if dark mode is used anywhere in the project
