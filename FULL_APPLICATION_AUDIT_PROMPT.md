# SimpleAd Manager — Full Application Audit & Functionality Verification

> **Purpose:** This document serves as a prompt for Claude Code to perform a complete, page-by-page, function-by-function audit of the entire application. The output should be a comprehensive TODO list with every issue, missing feature, duplicate code, and improvement needed.
>
> **Output:** Generate a file called `AUDIT_RESULTS.md` in the project root with the complete findings.

---

## Context

SimpleAd Manager is a centralized WordPress site management platform built with:
- **Backend:** Laravel 11 + Livewire 3
- **Frontend:** Alpine.js + Tailwind CSS
- **Database:** PostgreSQL
- **Cache/Queue:** Redis
- **Server:** Nginx in Docker on AWS EC2
- **Design:** WPMUDEV-inspired (dark sidebar `#1A1A2E`, purple accent `#8D5CF5`, Inter font)
- **Domain:** manager.simplead.ro

---

## Instructions for Claude Code

### Step 0: Discovery — Map the Entire Application

Before auditing anything, build a complete map of the application:

```bash
# 1. List all routes
php artisan route:list --columns=method,uri,name,action

# 2. List all Livewire components
find app/Livewire -name "*.php" -type f | sort

# 3. List all Blade views
find resources/views -name "*.blade.php" -type f | sort

# 4. List all Models
find app/Models -name "*.php" -type f | sort

# 5. List all Services
find app/Services -name "*.php" -type f | sort

# 6. List all Migrations
ls -la database/migrations/

# 7. List all Jobs/Commands
find app/Jobs -name "*.php" -type f | sort
find app/Console/Commands -name "*.php" -type f | sort

# 8. List all shared Blade components
find resources/views/components -name "*.blade.php" -type f | sort

# 9. List all config files
ls app/config/ 2>/dev/null; ls config/

# 10. Check Docker structure
ls docker/ 2>/dev/null; cat docker-compose.yml 2>/dev/null | head -100
```

Put this full map at the top of `AUDIT_RESULTS.md` under a "## Application Map" section.

---

### Step 1: Audit Each Page/Module

For **EVERY** page/module in the application, perform the following checks. Go through them one by one — do not skip any.

#### Per-Page Checklist:

```
For [PAGE NAME] at [ROUTE]:

1. FUNCTIONALITY
   - [ ] Does the page load without errors?
   - [ ] Do all CRUD operations work? (Create, Read, Update, Delete)
   - [ ] Does search/filtering work?
   - [ ] Does pagination work?
   - [ ] Do all buttons/links lead to correct destinations?
   - [ ] Do forms validate correctly (both client-side and server-side)?
   - [ ] Do flash messages / notifications appear correctly?
   - [ ] Does the page handle empty states gracefully?
   - [ ] Are loading states shown during async operations?
   - [ ] Do modals open/close correctly?
   - [ ] Does real-time data update correctly (wire:poll, events)?

2. CODE QUALITY
   - [ ] Is there duplicate code that exists in other components?
   - [ ] Are there inline styles that should use Tailwind classes?
   - [ ] Are there hardcoded strings that should be constants or config values?
   - [ ] Is there dead/unreachable code?
   - [ ] Are there unused imports or variables?
   - [ ] Are Livewire lifecycle methods used correctly?
   - [ ] Is Alpine.js used correctly (no conflicts, proper x-data)?
   - [ ] Are there N+1 query issues? (check with debugbar or manual review)
   - [ ] Are database queries optimized (proper indexes, eager loading)?

3. COMPONENT REUSABILITY
   - [ ] Could any part of this page be extracted into a shared component?
   - [ ] Is this page using existing shared components where it should?
   - [ ] Are there similar patterns on other pages that could share code?
   - [ ] List specific components that should be shared:
         - Search input → <x-ui.search-input>
         - Filter tabs → <x-ui.filter-tabs>
         - Page header → <x-ui.page-header>
         - Empty state → <x-ui.empty-state>
         - Status badge → <x-ui.badge>
         - Data table → <x-ui.table>
         - Confirmation modal → <x-ui.confirm-modal>
         - Stats cards → <x-ui.stat-card>
         - Date range picker → <x-ui.date-range>

4. UX CONSISTENCY
   - [ ] Does the page follow the design system (colors, spacing, typography)?
   - [ ] Is the page header consistent with other pages?
   - [ ] Are button styles consistent (primary=purple, danger=red, etc.)?
   - [ ] Are form input styles consistent?
   - [ ] Is the table style consistent with other tables?
   - [ ] Are hover/focus states consistent?
   - [ ] Is the responsive layout working (mobile, tablet, desktop)?
   - [ ] Is the sidebar active state correct for this page?

5. SECURITY
   - [ ] Are routes protected by auth middleware?
   - [ ] Is authorization checked (policies, gates)?
   - [ ] Are user inputs sanitized/validated?
   - [ ] Are there any mass assignment vulnerabilities?
   - [ ] Are file uploads validated (if applicable)?
```

---

### Step 2: Audit These Specific Modules

Apply the checklist above to each of these modules. If a module doesn't exist yet, mark it as **MISSING** with what needs to be built:

#### Global Pages
1. **Dashboard** (`/dashboard`)
   - Main overview page with stats cards
   - Recent activity feed
   - Quick actions

2. **Sites List** (`/sites`)
   - Grid/list of all managed sites
   - Search, filter by status
   - Site card with quick info
   - Add new site button

3. **Site Detail / Overview** (`/sites/{id}`)
   - Site dashboard with feature cards
   - Quick stats (uptime, performance, etc.)
   - Site-context sidebar switching

4. **Uptime Monitoring** (`/uptime` and `/sites/{id}/uptime`)
   - Global uptime overview across all sites
   - Per-site uptime detail with charts
   - Incident history
   - Response time graphs
   - Check intervals configuration

5. **SSL & Domain Monitoring** (`/sites/{id}/ssl`)
   - SSL certificate details and expiry
   - Domain expiry tracking
   - DNS records
   - Auto-renewal status

6. **Performance** (`/sites/{id}/performance`)
   - Page speed scores
   - Performance history charts
   - Recommendations
   - Core Web Vitals

7. **Backups** (`/sites/{id}/backups`)
   - Backup list with status
   - Manual backup trigger
   - Scheduled backup configuration
   - Backup destinations (local, S3)
   - Restore functionality
   - Storage usage

8. **Clients** (`/clients`)
   - **CRITICAL: Verify CRUD exists** (was identified as missing)
   - Client list with search/filter
   - Create/Edit client form
   - Client detail page
   - Associated sites
   - Romanian business fields (CUI, Registration Number)

9. **Reports** (`/reports`)
   - Report generation
   - PDF export
   - Scheduled reports
   - Per-client reports
   - Multi-language support (RO/EN)
   - Proper margins and diacritics in PDFs

10. **Settings** (`/settings`)
    - General settings
    - Notification preferences
    - Integration settings
    - User profile
    - API keys management

11. **Security Module** (`/sites/{id}/security`)
    - **Verify if implemented or missing**
    - Security score
    - Vulnerability scanning
    - File integrity checks
    - Security headers
    - Hardening recommendations

12. **Analytics** (`/sites/{id}/analytics`)
    - Google Analytics integration
    - Visitor stats
    - Page views
    - Traffic sources

13. **Search Console** (`/sites/{id}/search-console`)
    - Google Search Console integration
    - Search queries
    - Indexing status
    - Sitemap status

14. **Updates / Plugins & Themes** (`/sites/{id}/updates`)
    - WordPress core updates
    - Plugin updates
    - Theme updates
    - Auto-update configuration

15. **Error Logs** (`/sites/{id}/errors`)
    - Error log aggregation
    - Severity filtering
    - Error trends

16. **Link Checker** (`/sites/{id}/links`)
    - Broken link detection
    - Link status overview

---

### Step 3: Cross-Cutting Concerns Audit

After individual page audits, check these system-wide concerns:

#### 3.1 Shared Components Inventory

```bash
# List all blade components
find resources/views/components -name "*.blade.php" | sort
```

For each shared component, verify:
- Is it used consistently across all pages that need it?
- Are there pages that have inline code that should use this component?
- Is the component API (props, slots) well-designed?

Create a matrix:

| Component | Dashboard | Sites | Uptime | Clients | Reports | Settings | Site Detail |
|-----------|-----------|-------|--------|---------|---------|----------|-------------|
| page-header | ? | ? | ? | ? | ? | ? | ? |
| search-input | ? | ? | ? | ? | ? | ? | ? |
| filter-tabs | ? | ? | ? | ? | ? | ? | ? |
| stat-card | ? | ? | ? | ? | ? | ? | ? |
| empty-state | ? | ? | ? | ? | ? | ? | ? |
| badge | ? | ? | ? | ? | ? | ? | ? |
| table | ? | ? | ? | ? | ? | ? | ? |
| modal | ? | ? | ? | ? | ? | ? | ? |
| confirm-modal | ? | ? | ? | ? | ? | ? | ? |

Mark with ✅ (uses it), ❌ (should use but doesn't), ➖ (not applicable).

#### 3.2 Duplicate Code Detection

```bash
# Find similar code patterns
# Look for duplicated Livewire patterns
grep -rn "public string \$search" app/Livewire/ | head -20
grep -rn "public string \$filter" app/Livewire/ | head -20
grep -rn "WithPagination" app/Livewire/ | head -20
grep -rn "wire:model" resources/views/livewire/ | head -20

# Find similar Blade patterns (search inputs, filters, tables)
grep -rn "wire:model.*search" resources/views/ | head -20
grep -rn "empty-state\|No .* found\|nothing here" resources/views/ | head -20
```

For each duplicate pattern found, document:
- Where it appears (file paths)
- What shared component/trait could replace it
- Effort level (low/medium/high)

#### 3.3 Service Layer Audit

```bash
find app/Services -name "*.php" | sort
```

For each service:
- Is it used by the correct Livewire components?
- Are there Livewire components with business logic that should be in a service?
- Are there unused services?
- Are services properly injected (constructor injection, not Facades where DI is better)?

#### 3.4 Model & Database Audit

For each model:
- Are all relationships defined?
- Are fillable/guarded properly set?
- Are there missing indexes on frequently queried columns?
- Are scopes defined for common query patterns?
- Are casts defined for JSON, date, enum fields?
- Is soft delete configured where needed?

```bash
# Check for missing indexes
php artisan db:show --counts 2>/dev/null || echo "Check manually"

# Look for common query patterns without indexes
grep -rn "->where(" app/ | grep -v "vendor" | head -30
```

#### 3.5 Queue & Job Audit

```bash
find app/Jobs -name "*.php" | sort
```

For each job:
- Is it dispatched correctly?
- Does it have proper retry/failure handling?
- Is the queue name correct?
- Is it tested?

#### 3.6 Routes & Middleware Audit

```bash
php artisan route:list
```

Check:
- All routes have proper middleware (auth, verified)
- Route names follow consistent convention
- No orphan routes (routes without controllers/views)
- No duplicate routes
- API routes are properly grouped

#### 3.7 Docker & Infrastructure Audit

Review:
- `docker-compose.yml` — services, volumes, networks
- `Dockerfile` — multi-stage build, security
- Nginx config — SSL, headers, rate limiting
- PHP-FPM config — workers, memory
- Redis config — persistence, maxmemory
- PostgreSQL config — connections, WAL
- `.env.example` — all required vars documented
- Horizon config — queue workers, balancing
- Scheduler — all scheduled commands

---

### Step 4: Generate the Final Report

The `AUDIT_RESULTS.md` file should have this structure:

```markdown
# SimpleAd Manager — Complete Audit Results
> Generated: [DATE]
> Audited by: Claude Code

## Application Map
[Full directory/file listing from Step 0]

## Executive Summary
- Total pages/modules audited: X
- Fully functional: X
- Partially functional: X
- Missing/Not implemented: X
- Critical issues found: X
- Duplicate code instances: X
- Missing shared component usage: X

## Module-by-Module Audit

### 1. Dashboard
**Route:** /dashboard
**Status:** ✅ Functional / ⚠️ Partial / ❌ Missing
**Livewire Component:** [path]
**Blade View:** [path]

#### Issues Found
| # | Type | Severity | Description | Fix |
|---|------|----------|-------------|-----|
| 1 | Bug | High | ... | ... |
| 2 | Duplicate | Medium | ... | Extract to shared component |
| 3 | UX | Low | ... | ... |

#### Missing Functionality
- [ ] Feature X not implemented
- [ ] Feature Y partially working

[Repeat for every module]

## Cross-Cutting Issues

### Shared Component Usage Matrix
[The table from 3.1]

### Duplicate Code Inventory
| Pattern | Occurrences | Files | Suggested Fix |
|---------|-------------|-------|---------------|
| Search input inline | 5 | file1, file2... | Use <x-ui.search-input> |
| ... | ... | ... | ... |

### Service Layer Issues
[Findings from 3.3]

### Database & Model Issues
[Findings from 3.4]

### Infrastructure Issues
[Findings from 3.7]

## Prioritized TODO List

### 🔴 Critical (Must fix before production)
- [ ] Issue 1
- [ ] Issue 2

### 🟡 High Priority (Should fix soon)
- [ ] Issue 3
- [ ] Issue 4

### 🟢 Medium Priority (Improve quality)
- [ ] Issue 5
- [ ] Issue 6

### 🔵 Low Priority (Nice to have)
- [ ] Issue 7
- [ ] Issue 8

## Estimated Effort
| Category | Count | Est. Hours |
|----------|-------|------------|
| Critical bugs | X | X |
| Missing features | X | X |
| Code dedup | X | X |
| UX fixes | X | X |
| Infrastructure | X | X |
| **Total** | **X** | **X** |
```

---

## Important Rules

1. **Be thorough, not fast.** Check every file, every route, every component. Do not assume anything works — verify it.

2. **Actually run the code.** Don't just read files — use `php artisan route:list`, try to trigger Livewire actions, check if views render.

3. **Check the database.** Verify migrations have run, tables exist, relationships work:
   ```bash
   php artisan migrate:status
   php artisan tinker --execute="App\Models\Site::count()"
   ```

4. **Look for hidden issues:**
   - Missing `wire:key` on loops
   - Missing `@error` blocks on forms
   - Missing CSRF tokens
   - Broken Alpine.js bindings
   - Console errors in rendered HTML

5. **Compare similar pages.** When auditing Sites list, compare its code with Clients list, Uptime list, etc. They should follow the same patterns.

6. **Check for TODO/FIXME comments:**
   ```bash
   grep -rn "TODO\|FIXME\|HACK\|XXX\|TEMP" app/ resources/ --include="*.php" --include="*.blade.php"
   ```

7. **Verify the sidebar** shows correct active states for every page.

8. **Test empty states** — what happens when there are no sites, no clients, no backups?

9. **Check error handling** — what happens when an API call fails, a job throws an exception, or a form submission has invalid data?

10. **Document everything** — even small inconsistencies matter for production readiness.

---

## Prompt to Use with Claude Code

Copy and paste this when starting the audit:

```
Read the file FULL_APPLICATION_AUDIT_PROMPT.md in the project root.

This is a comprehensive audit methodology for the SimpleAd Manager application.

Your task:
1. Follow the instructions exactly as written in the document
2. Start with Step 0 (Discovery) to map the entire application
3. Then audit every single page/module using the checklist from Step 1
4. Perform the cross-cutting analysis from Step 3
5. Generate AUDIT_RESULTS.md with the exact structure from Step 4

Be extremely thorough. Check every file, every route, every component.
Do not skip anything. Do not assume anything works — verify it.

Start now with Step 0.
```
