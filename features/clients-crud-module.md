# Clients CRUD Module - Complete Specification

## Context

SimpleAd Manager is a centralized WordPress site management platform built with Laravel 11, Livewire 3, Alpine.js, and Tailwind CSS. The platform uses a dark sidebar with purple accent color (#8D5CF5) and Inter font family.

The `Client` model and basic migration already exist in the project (fields: name, email, company). However, there is currently **no UI for managing clients** — no add, edit, delete, or detail views. This specification covers the complete Clients CRUD module.

Clients are used throughout the platform:
- Each `Site` belongs to a `Client` (client_id on sites table)
- PDF reports can be generated per client
- The sidebar already has a "Clients" link under the MANAGEMENT section

---

## Tech Stack & Conventions

- **Backend:** Laravel 11 + Livewire 3
- **Frontend:** Alpine.js + Tailwind CSS (dark sidebar, purple accents)
- **Icons:** Heroicons (outline style for sidebar, solid for inline)
- **Font:** Inter (system font stack fallback)
- **Components:** Livewire components in `app/Livewire/`
- **Views:** `resources/views/livewire/`
- **Routes:** `web.php` with named routes
- **Existing patterns:** Follow the same patterns used in Sites, Uptime, Settings pages

---

## Database

### Update Existing `clients` Migration

If the current `clients` table only has basic fields, create a new migration to add missing columns:

```php
Schema::table('clients', function (Blueprint $table) {
    // Contact info
    $table->string('phone')->nullable()->after('email');
    $table->string('website')->nullable()->after('phone');
    
    // Address
    $table->string('address')->nullable()->after('website');
    $table->string('city')->nullable()->after('address');
    $table->string('country')->nullable()->after('city');
    
    // Business details
    $table->string('vat_number')->nullable()->after('country'); // CUI / VAT for Romanian clients
    $table->string('registration_number')->nullable()->after('vat_number'); // Nr. Reg. Com.
    
    // Internal notes
    $table->text('notes')->nullable()->after('registration_number');
    
    // Status
    $table->enum('status', ['active', 'inactive', 'archived'])->default('active')->after('notes');
    
    // Avatar/logo
    $table->string('logo_path')->nullable()->after('status');
    
    // Soft deletes
    $table->softDeletes();
    
    // Indexes
    $table->index('status');
    $table->index('company');
});
```

### Update `Client` Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'website',
        'address',
        'city',
        'country',
        'vat_number',
        'registration_number',
        'notes',
        'status',
        'logo_path',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    // Relationships
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('company', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    // Accessors
    public function getDisplayNameAttribute(): string
    {
        return $this->company ?: $this->name;
    }

    public function getSitesCountAttribute(): int
    {
        return $this->sites()->count();
    }

    public function getActiveSitesCountAttribute(): int
    {
        return $this->sites()->where('status', 'active')->count();
    }

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->company ?: $this->name);
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return $initials;
    }
}
```

---

## Routes

Add to `web.php`:

```php
// Clients
Route::prefix('clients')->group(function () {
    Route::get('/', Clients\ClientsList::class)->name('clients.index');
    Route::get('/create', Clients\ClientForm::class)->name('clients.create');
    Route::get('/{client}', Clients\ClientDetail::class)->name('clients.show');
    Route::get('/{client}/edit', Clients\ClientForm::class)->name('clients.edit');
});
```

---

## Livewire Components

### 1. ClientsList (`app/Livewire/Clients/ClientsList.php`)

**Route:** `/clients`  
**View:** `resources/views/livewire/clients/clients-list.blade.php`  
**Layout:** Uses the main app layout with global sidebar

#### Properties

```php
public string $search = '';
public string $statusFilter = 'all'; // all, active, inactive, archived
public string $sortBy = 'name';
public string $sortDirection = 'asc';
public int $perPage = 25;
```

#### Features

- **Search bar** at the top — searches across name, email, company, phone
- **Status filter** tabs/pills: All | Active | Inactive | Archived (with counts)
- **"Add Client" button** (purple, top right) → links to `/clients/create`
- **Sortable columns**: Name/Company, Email, Sites, Status, Created
- **Pagination** with per-page selector (10, 25, 50)
- **Bulk actions** (optional, can be added later): Delete selected, Change status

#### Client Row Display

Each row in the table shows:

```
┌──────────────────────────────────────────────────────────────────────┐
│ [Avatar/Initials]  Company Name          email@example.com          │
│                    Contact Person Name    +40 712 345 678            │
│                                                                      │
│ 🌐 5 sites    ● Active    Created: Jan 15, 2025                     │
│                                                                      │
│                                          [Edit] [View] [⋮ More]     │
└──────────────────────────────────────────────────────────────────────┘
```

**Alternative: Card layout** (responsive, better on mobile)

For desktop, use a clean table layout. On mobile (<768px), switch to card layout.

#### Table Columns (Desktop)

| Column | Content | Width | Sortable |
|--------|---------|-------|----------|
| Client | Avatar + Company + Name + Email | flex | ✅ (by name) |
| Phone | Phone number | 150px | ❌ |
| Sites | Count badge (link to filtered sites list) | 80px | ✅ |
| Status | Colored badge (Active/Inactive/Archived) | 100px | ✅ |
| Created | Date | 120px | ✅ |
| Actions | Edit, View, Delete dropdown | 100px | ❌ |

#### Status Badge Colors

- **Active:** Green background/text (`bg-green-100 text-green-700`)
- **Inactive:** Yellow/amber (`bg-yellow-100 text-yellow-700`)
- **Archived:** Gray (`bg-gray-100 text-gray-500`)

#### Empty State

When no clients exist, show a centered empty state:
- Icon: `heroicon-o-user-group` (large, gray)
- Title: "No clients yet"
- Subtitle: "Add your first client to start organizing your sites."
- CTA button: "Add Client" (purple)

#### Actions Dropdown (three dots menu)

- **View** → `/clients/{id}`
- **Edit** → `/clients/{id}/edit`
- **Divider**
- **Change Status** → submenu: Active, Inactive, Archived
- **Divider**
- **Delete** → confirmation modal

#### Delete Confirmation Modal

```
┌──────────────────────────────────────┐
│  ⚠️ Delete Client                    │
│                                      │
│  Are you sure you want to delete     │
│  "Company Name"?                     │
│                                      │
│  This client has 5 associated sites. │
│  The sites will NOT be deleted but   │
│  will be unlinked from this client.  │
│                                      │
│  [Cancel]          [Delete Client]   │
│                    (red button)       │
└──────────────────────────────────────┘
```

---

### 2. ClientForm (`app/Livewire/Clients/ClientForm.php`)

**Routes:** `/clients/create` and `/clients/{client}/edit`  
**View:** `resources/views/livewire/clients/client-form.blade.php`

This component handles both **create** and **edit** modes, determined by whether a `$client` model is passed via route model binding.

#### Properties

```php
public ?Client $client = null;

// Form fields
public string $name = '';
public string $email = '';
public string $phone = '';
public string $company = '';
public string $website = '';
public string $address = '';
public string $city = '';
public string $country = '';
public string $vat_number = '';
public string $registration_number = '';
public string $notes = '';
public string $status = 'active';
```

#### Validation Rules

```php
protected function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:50',
        'company' => 'nullable|string|max:255',
        'website' => 'nullable|url|max:255',
        'address' => 'nullable|string|max:500',
        'city' => 'nullable|string|max:255',
        'country' => 'nullable|string|max:255',
        'vat_number' => 'nullable|string|max:50',
        'registration_number' => 'nullable|string|max:50',
        'notes' => 'nullable|string|max:5000',
        'status' => 'required|in:active,inactive,archived',
    ];
}
```

#### Form Layout

The form should be organized in logical sections using a **two-column layout on desktop**, single column on mobile.

```
Page Title: "Add New Client" or "Edit Client: Company Name"
Breadcrumb: Clients > Add New / Edit

┌─────────────────────────────────────────────────────────┐
│  CONTACT INFORMATION                                     │
│  ┌─────────────────────┐  ┌─────────────────────┐       │
│  │ Contact Name *       │  │ Email                │       │
│  └─────────────────────┘  └─────────────────────┘       │
│  ┌─────────────────────┐  ┌─────────────────────┐       │
│  │ Phone                │  │ Website              │       │
│  └─────────────────────┘  └─────────────────────┘       │
├─────────────────────────────────────────────────────────┤
│  COMPANY DETAILS                                         │
│  ┌───────────────────────────────────────────────┐       │
│  │ Company Name                                   │       │
│  └───────────────────────────────────────────────┘       │
│  ┌─────────────────────┐  ┌─────────────────────┐       │
│  │ VAT Number (CUI)     │  │ Reg. Number          │       │
│  └─────────────────────┘  └─────────────────────┘       │
├─────────────────────────────────────────────────────────┤
│  ADDRESS                                                 │
│  ┌───────────────────────────────────────────────┐       │
│  │ Address                                        │       │
│  └───────────────────────────────────────────────┘       │
│  ┌─────────────────────┐  ┌─────────────────────┐       │
│  │ City                 │  │ Country              │       │
│  └─────────────────────┘  └─────────────────────┘       │
├─────────────────────────────────────────────────────────┤
│  ADDITIONAL                                              │
│  ┌─────────────────────┐                                 │
│  │ Status  [dropdown]   │                                 │
│  └─────────────────────┘                                 │
│  ┌───────────────────────────────────────────────┐       │
│  │ Notes (textarea, 4 rows)                       │       │
│  └───────────────────────────────────────────────┘       │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  [Cancel]                    [Save Client] (purple btn)  │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

#### Form Sections Styling

Each section has:
- A section title: `text-sm font-medium text-gray-500 uppercase tracking-wider`
- A subtle top border for separation (except first section)
- Padding: `py-6`

#### Input Styling

Follow existing input styles in the project. If none established, use:

```html
<input type="text" 
    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm 
           focus:border-purple-500 focus:ring-1 focus:ring-purple-500 
           placeholder-gray-400">
```

#### Save Behavior

- **Create mode:** After save, redirect to `/clients/{id}` (detail page) with success toast
- **Edit mode:** After save, redirect to `/clients/{id}` (detail page) with success toast
- **Cancel:** Redirect back to `/clients`

#### Keyboard

- `Ctrl+S` / `Cmd+S` to save (wire:keydown.ctrl.s)

---

### 3. ClientDetail (`app/Livewire/Clients/ClientDetail.php`)

**Route:** `/clients/{client}`  
**View:** `resources/views/livewire/clients/client-detail.blade.php`

#### Layout

```
Page Title: "Company Name" (or Contact Name if no company)
Breadcrumb: Clients > Company Name

┌──────────────────────────────────────────────────────────────┐
│  HEADER                                                       │
│  ┌────┐                                                       │
│  │ AB │  Company Name                        [Edit] [Delete]  │
│  │    │  Contact Name • email@example.com                     │
│  └────┘  📞 +40 712 345 678 • 🌐 example.com                 │
│          Status: ● Active                                     │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────┐  ┌──────────────────────────────┐
│  DETAILS                  │  │  SITES (5)                    │
│                           │  │                               │
│  Company: ABC SRL         │  │  ┌─ site1.com  ● Up  ──────┐ │
│  VAT: RO12345678          │  │  │  Health: 92  SSL: ✓      │ │
│  Reg: J12/345/2020        │  │  │  Last backup: 2 days ago │ │
│  Address: Str. Example 1  │  │  └────────────────────────┘ │
│  City: Galați              │  │  ┌─ site2.com  ● Up  ──────┐ │
│  Country: Romania          │  │  │  Health: 78  SSL: ✓      │ │
│                           │  │  │  Last backup: 5 days ago │ │
│  NOTES                    │  │  └────────────────────────┘ │
│  Internal note text here  │  │                               │
│  displayed read-only      │  │  [+ Add Site for this Client] │
│                           │  │                               │
└──────────────────────────┘  └──────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  RECENT REPORTS                                               │
│                                                               │
│  📄 Monthly Report - January 2025    [Download] [View]        │
│  📄 Monthly Report - December 2024   [Download] [View]        │
│                                                               │
│  [Generate Report]                                            │
└──────────────────────────────────────────────────────────────┘
```

#### Detail Page Sections

**1. Header Card**
- Client avatar (initials in colored circle) or uploaded logo
- Company name (large, bold)
- Contact name, email (clickable mailto:), phone (clickable tel:)
- Website link (opens in new tab)
- Status badge
- Edit button (outline, purple) and Delete button (outline, red) in top-right

**2. Details Card (left column)**
- Company information: VAT, registration number
- Address: full address, city, country
- Notes: displayed as preformatted text, read-only
- "Created" and "Last updated" timestamps at the bottom

**3. Sites Card (right column)**
- List of all sites associated with this client
- Each site shows: domain, uptime status, health score, SSL status, last backup
- Click on a site → navigates to site overview
- If no sites: "No sites linked to this client" + button to add
- "+ Add Site" link that goes to site creation with client pre-selected

**4. Reports Card (full width, below)**
- List of recent PDF reports generated for this client
- Each shows: report name, date, download + view buttons
- "Generate Report" button
- If no reports: "No reports generated yet"

#### Quick Actions

- **Edit:** Button in header → `/clients/{id}/edit`
- **Delete:** Button in header → opens confirmation modal
- **Generate Report:** Button in reports section
- **Add Site:** Link in sites section → `/sites/create?client_id={id}`

---

## Blade Components

### Reusable Components to Create

#### 1. Client Avatar Component

```html
<!-- resources/views/components/client-avatar.blade.php -->
<!-- Props: $client, $size = 'md' (sm|md|lg) -->

<!-- Shows logo if available, otherwise colored initials circle -->
<!-- Color generated from client name hash for consistency -->
```

Sizes:
- `sm`: 32x32, text-xs
- `md`: 40x40, text-sm
- `lg`: 64x64, text-lg

#### 2. Status Badge Component

```html
<!-- resources/views/components/status-badge.blade.php -->
<!-- Props: $status -->
<!-- Renders colored badge based on status value -->
```

---

## Toast Notifications

Use the existing notification dispatch pattern:

```php
$this->dispatch('notify', type: 'success', message: 'Client created successfully.');
$this->dispatch('notify', type: 'success', message: 'Client updated successfully.');
$this->dispatch('notify', type: 'success', message: 'Client deleted successfully.');
$this->dispatch('notify', type: 'error', message: 'Failed to delete client.');
```

---

## Sidebar Integration

The "Clients" link should already exist in the MANAGEMENT section of the global sidebar. Ensure the active state works correctly:

```html
<x-sidebar.sidebar-item 
    :href="route('clients.index')" 
    icon="user-group" 
    :active="request()->routeIs('clients.*')">
    Clients
</x-sidebar.sidebar-item>
```

---

## Search & Filter Behavior

### URL Query Parameters

The list page should sync filters to the URL for shareability:

```php
#[Url]
public string $search = '';

#[Url]
public string $statusFilter = 'all';

#[Url]
public string $sortBy = 'name';

#[Url]
public string $sortDirection = 'asc';
```

### Debounced Search

```php
// In the view:
<input type="text" wire:model.live.debounce.300ms="search" placeholder="Search clients...">
```

---

## Integration Points

### 1. Sites Module

When creating/editing a site, the client dropdown should:
- Show all active clients
- Allow search/filter within the dropdown
- Show "Create New Client" option at the bottom that opens a modal or redirects

### 2. Reports Module

When generating a report, it should be possible to:
- Select a client from a dropdown
- The report header shows client company name and contact info
- Reports index can be filtered by client

### 3. Dashboard

The global dashboard could show:
- Total clients count in stats bar
- Recently added clients in activity feed

---

## Data Seeding

Create a seeder with realistic Romanian client data:

```php
// database/seeders/ClientSeeder.php

$clients = [
    [
        'name' => 'Ion Popescu',
        'email' => 'ion@webdesign.ro',
        'phone' => '+40 721 123 456',
        'company' => 'WebDesign SRL',
        'website' => 'https://webdesign.ro',
        'address' => 'Str. Brăilei, Nr. 150',
        'city' => 'Galați',
        'country' => 'Romania',
        'vat_number' => 'RO12345678',
        'registration_number' => 'J17/100/2020',
        'status' => 'active',
    ],
    [
        'name' => 'Maria Ionescu',
        'email' => 'maria@digitalagency.ro',
        'phone' => '+40 731 234 567',
        'company' => 'Digital Agency SRL',
        'website' => 'https://digitalagency.ro',
        'address' => 'Bd. Coșbuc, Nr. 45',
        'city' => 'Galați',
        'country' => 'Romania',
        'vat_number' => 'RO87654321',
        'registration_number' => 'J17/200/2019',
        'status' => 'active',
    ],
    // Add 5-8 more clients with varied statuses
];
```

---

## Implementation Checklist

### Phase 1: Backend (do first)
- [ ] Create migration to add new columns to `clients` table
- [ ] Update `Client` model with fillable, casts, relationships, scopes, accessors
- [ ] Create `ClientSeeder` with realistic data
- [ ] Run migration and seeder

### Phase 2: List Page
- [ ] Create `ClientsList` Livewire component
- [ ] Create list view with table layout
- [ ] Implement search (debounced, 300ms)
- [ ] Implement status filter tabs with counts
- [ ] Implement sortable columns
- [ ] Implement pagination
- [ ] Create empty state
- [ ] Add delete confirmation modal
- [ ] Add status change action
- [ ] Ensure sidebar "Clients" link has correct active state

### Phase 3: Form (Create & Edit)
- [ ] Create `ClientForm` Livewire component
- [ ] Handle both create and edit modes via route model binding
- [ ] Build form with sections: Contact, Company, Address, Additional
- [ ] Implement validation rules
- [ ] Add save/cancel actions with redirects
- [ ] Add success toast notifications
- [ ] Add Ctrl+S keyboard shortcut

### Phase 4: Detail Page
- [ ] Create `ClientDetail` Livewire component
- [ ] Build header with avatar, info, action buttons
- [ ] Build details card (left column)
- [ ] Build sites list card (right column) with live site data
- [ ] Build reports card (full width)
- [ ] Add delete action with modal
- [ ] Add "Add Site" and "Generate Report" quick actions

### Phase 5: Integration
- [ ] Update site create/edit form to include client selector
- [ ] Update dashboard to show total clients count
- [ ] Verify sidebar active states work correctly
- [ ] Test all flows end-to-end

### Phase 6: Polish
- [ ] Create `client-avatar` Blade component
- [ ] Create `status-badge` Blade component (if not already reusable)
- [ ] Responsive design testing (mobile card layout)
- [ ] Keyboard accessibility (tab order, enter to submit)
- [ ] Loading states on all actions

---

## Important Notes

1. **Follow existing project patterns** — look at how Sites, Uptime, Settings pages are structured and follow the same conventions for layout, styling, and component organization.

2. **Purple accent color** (#8D5CF5) for primary buttons, focus states, and active sidebar items.

3. **Do not create a separate CSS file** — use Tailwind utility classes exclusively.

4. **Soft deletes** — clients are soft-deleted. The "Archived" status is separate from soft delete. Soft delete is only used when explicitly deleting from the actions dropdown.

5. **No logo upload for now** — the avatar shows initials. Logo upload can be added later as an enhancement.

6. **Romanian context** — VAT number (CUI) and Registration Number (Nr. Reg. Com.) are important fields for Romanian business clients. Keep them visible but not required.
