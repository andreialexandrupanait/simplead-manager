# Security Hardening Module — Claude Code Implementation Prompt

> **Document type:** Claude Code implementation brief
> **Module:** Security Hardening (new module within SimpleAD Manager)
> **Stack:** Laravel 11, Livewire 3, Alpine.js, Tailwind CSS, PostgreSQL 16
> **Date:** March 2026

---

## Context & Background

SimpleAD Manager is a centralized WordPress site management platform. We are adding a **Security Hardening module** inspired by Patchstack's feature set. This module allows operators to remotely configure security settings across all managed WordPress sites from a central dashboard.

The platform already has:
- A WordPress agent plugin deployed to client sites that collects data and enables remote management
- A site presets system for applying configurations to multiple sites
- Monitoring infrastructure (uptime, SSL, performance)
- PDF reporting system for monthly client reports
- Job queue system with Horizon for background processing
- Existing models for Sites, Clients, and related entities (~95 tables, ~79 models)

**This module does NOT include vulnerability detection/scanning or virtual patching.** It focuses purely on hardening configuration, login protection, activity logging, user auditing, IP management, and security presets that can be applied across sites.

---

## Agent-Server Communication Architecture

The WordPress agent needs a **command queue system** (hybrid pull model) for security settings.

### How It Works

1. **Central server stores pending commands** in a `security_commands` queue table, each targeting a specific site
2. **Agent polls the central server** on a regular interval (every 5 minutes via WP Cron or a custom cron) by calling `GET /api/agent/{site_token}/security/pending-commands`
3. **Agent executes commands locally** (apply .htaccess rules, toggle wp-config constants, etc.)
4. **Agent reports results back** via `POST /api/agent/{site_token}/security/command-results` with success/failure status per command
5. **Central server updates command status** and the site security profile

### Why Pull (Not Push)

- Most WordPress sites are behind shared hosting, firewalls, or do not have publicly accessible API endpoints
- No need to store site credentials or API keys on the central server
- Agent controls its own execution timing, reducing risk of race conditions
- Works reliably even when sites are temporarily offline (commands queue up)

### Command Structure

```json
{
  "command_id": "uuid",
  "category": "hardening|login|captcha|htaccess|ip_management|activity_log",
  "action": "apply_setting",
  "payload": {
    "setting_key": "disable_theme_editor",
    "value": true
  },
  "priority": "normal|high|critical",
  "created_at": "2026-03-03T10:00:00Z"
}
```

### Rollback Mechanism

For potentially breaking changes (especially .htaccess modifications):

1. Agent saves current state before applying changes
2. Agent applies changes
3. Agent performs a self-health-check (HTTP request to own homepage)
4. If health check fails, automatic rollback to saved state
5. Report failure + rollback to central server

---

## Database Schema

### Core Tables

```sql
CREATE TABLE security_settings (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL, -- 'hardening', 'htaccess', 'login', 'captcha', 'ip_management', 'activity_log'
    setting_key VARCHAR(100) NOT NULL,
    setting_value JSONB NOT NULL DEFAULT '{}',
    is_enabled BOOLEAN NOT NULL DEFAULT false,
    applied_at TIMESTAMP NULL, -- when agent last confirmed this was applied
    failed_at TIMESTAMP NULL,
    failure_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, category, setting_key)
);

CREATE TABLE security_commands (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL, -- 'apply_setting', 'rollback', 'sync_all', 'health_check'
    payload JSONB NOT NULL DEFAULT '{}',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal', -- 'normal', 'high', 'critical'
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending', 'picked_up', 'completed', 'failed', 'rolled_back'
    picked_up_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    result JSONB NULL, -- agent response payload
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_security_commands_pending ON security_commands(site_id, status) WHERE status = 'pending';

CREATE TABLE security_presets (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    settings JSONB NOT NULL DEFAULT '{}', -- full settings snapshot
    is_default BOOLEAN NOT NULL DEFAULT false,
    created_by BIGINT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE security_preset_site (
    id BIGSERIAL PRIMARY KEY,
    security_preset_id BIGINT NOT NULL REFERENCES security_presets(id) ON DELETE CASCADE,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    applied_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(security_preset_id, site_id)
);

CREATE TABLE security_activity_logs (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL, -- 'failed_login', 'post_change', 'comment_change', 'plugin_activated', 'user_created'
    username VARCHAR(255) NULL,
    object_type VARCHAR(50) NULL, -- 'post', 'comment', 'plugin', 'theme', 'user', 'option'
    object_name VARCHAR(255) NULL,
    action VARCHAR(50) NOT NULL, -- 'login_failed', 'created', 'updated', 'deleted', 'activated', 'deactivated'
    ip_address INET NULL,
    user_agent TEXT NULL,
    details JSONB NULL,
    occurred_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_security_activity_logs_site ON security_activity_logs(site_id, occurred_at DESC);
CREATE INDEX idx_security_activity_logs_ip ON security_activity_logs(ip_address);
CREATE INDEX idx_security_activity_logs_event ON security_activity_logs(site_id, event_type);

CREATE TABLE security_site_users (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    wp_user_id INTEGER NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    display_name VARCHAR(255) NULL,
    role VARCHAR(50) NOT NULL, -- 'administrator', 'editor', 'author', 'contributor', 'subscriber'
    last_login_at TIMESTAMP NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    synced_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(site_id, wp_user_id)
);

CREATE TABLE security_ip_lists (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NULL REFERENCES sites(id) ON DELETE CASCADE, -- NULL = global (applies to all sites)
    ip_address INET NOT NULL,
    list_type VARCHAR(20) NOT NULL, -- 'whitelist', 'blocklist'
    reason VARCHAR(255) NULL,
    expires_at TIMESTAMP NULL, -- NULL = permanent
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_security_ip_lists_lookup ON security_ip_lists(site_id, list_type, ip_address);

CREATE TABLE security_banned_ips (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    ip_address INET NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT 'brute_force',
    blocked_attempts INTEGER NOT NULL DEFAULT 0,
    banned_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_security_banned_ips_active ON security_banned_ips(site_id, ip_address) WHERE expires_at > NOW();
```

---

## Settings Keys Reference

Complete map of `category` + `setting_key` combinations. The `setting_value` is always JSONB.

### Category: `hardening`

| setting_key | setting_value example | What it does on WordPress |
|---|---|---|
| `disable_theme_editor` | `{"enabled": true}` | Adds `DISALLOW_FILE_EDIT` to wp-config.php |
| `block_readme_access` | `{"enabled": true}` | Blocks access to readme.txt via .htaccess or PHP |
| `disable_user_enumeration` | `{"enabled": true}` | Blocks `?author=N` enumeration and REST API user listing |
| `hide_wp_version` | `{"enabled": true}` | Removes generator meta tag and version from scripts/styles |
| `block_application_passwords` | `{"enabled": true}` | Disables WP Application Passwords feature via `wp_is_application_passwords_available` filter |
| `restrict_xmlrpc` | `{"enabled": true}` | Restricts XML-RPC to authenticated users only |
| `restrict_rest_api` | `{"enabled": true}` | Restricts REST API to authenticated users only |

### Category: `htaccess`

| setting_key | setting_value example | What it does |
|---|---|---|
| `security_headers` | `{"enabled": true, "headers": {"x_frame_options": "SAMEORIGIN", "x_content_type": "nosniff", "referrer_policy": "strict-origin-when-cross-origin"}}` | Adds security headers via .htaccess |
| `block_default_files` | `{"enabled": true}` | Blocks access to license.txt, readme.html, wp-config-sample.php |
| `block_debug_log` | `{"enabled": true}` | Blocks access to debug.log |
| `disable_directory_listing` | `{"enabled": true}` | Adds `Options -Indexes` |
| `custom_rules` | `{"enabled": true, "rules": "# custom rules here", "position": "bottom"}` | Custom .htaccess rules with position (top/bottom relative to agent rules) |

### Category: `login`

| setting_key | setting_value example | What it does |
|---|---|---|
| `two_factor_auth` | `{"enabled": true}` | Enables 2FA option for site users |
| `custom_login_url` | `{"enabled": true, "slug": "my-secret-login"}` | Hides wp-login.php, creates custom URL. If slug is empty, agent generates a random hash |
| `login_ip_whitelist` | `{"enabled": true, "ips": ["1.2.3.4", "5.6.7.8"]}` | Only whitelisted IPs can access login page |
| `brute_force_protection` | `{"enabled": true, "block_duration_minutes": 60, "max_attempts": 10, "window_minutes": 5}` | Auto-ban IPs after N failed logins in time window |

### Category: `captcha`

| setting_key | setting_value example | What it does |
|---|---|---|
| `captcha_config` | `{"enabled": true, "provider": "cloudflare_turnstile", "site_key": "xxx", "secret_key": "xxx", "forms": ["login", "registration", "comments", "password_reset"]}` | Configures CAPTCHA on specified forms. Providers: `recaptcha_v2_checkbox`, `recaptcha_v2_invisible`, `recaptcha_v3`, `cloudflare_turnstile` |

### Category: `ip_management`

| setting_key | setting_value example | What it does |
|---|---|---|
| `firewall_enabled` | `{"enabled": true}` | Master toggle for the agent firewall/filtering layer |
| `user_role_whitelist` | `{"roles": ["administrator", "editor"]}` | Which user roles bypass protection rules |
| `ip_header_override` | `{"header": "HTTP_CF_CONNECTING_IP"}` | Which header to use for real IP (important behind CDN/proxy) |
| `auto_block_settings` | `{"block_duration_minutes": 1, "threshold": 60, "window_minutes": 1}` | Auto-block IPs after N blocked requests in time window |

### Category: `activity_log`

| setting_key | setting_value example | What it does |
|---|---|---|
| `activity_log_config` | `{"enabled": true, "log_posts": false, "log_comments": false, "log_failed_logins": true, "upload_to_central": true}` | Configures what gets logged and whether logs are sent to SimpleAD Manager |

---

## Laravel Models

### `SecuritySetting`
- Belongs to `Site`
- Scopes: `scopeForCategory($category)`, `scopeEnabled()`, `scopeApplied()`, `scopeFailed()`
- Cast `setting_value` to `array`

### `SecurityCommand`
- Belongs to `Site`
- Scopes: `scopePending()`, `scopeForSite($siteId)`, `scopeStale()` (picked_up but not completed after 30 min)
- Methods: `markPickedUp()`, `markCompleted($result)`, `markFailed($result)`, `shouldRetry()`

### `SecurityPreset`
- Belongs to many `Site` via `security_preset_site` pivot
- Belongs to `User` (created_by)
- Cast `settings` to `array`
- Scope: `scopeDefault()`

### `SecurityActivityLog`
- Belongs to `Site`
- Scopes: `scopeForEventType($type)`, `scopeForIp($ip)`, `scopeRecent($days = 7)`
- Read-only model (no updates, only inserts)

### `SecuritySiteUser`
- Belongs to `Site`
- Scopes: `scopePrivileged()` (administrator, editor, author, contributor), `scopeAdmins()`

### `SecurityIpList`
- Belongs to `Site` (nullable for global)
- Scopes: `scopeWhitelist()`, `scopeBlocklist()`, `scopeGlobal()`, `scopeForSite($siteId)`, `scopeActive()` (not expired)

### `SecurityBannedIp`
- Belongs to `Site`
- Scopes: `scopeActive()` (expires_at > now), `scopeForSite($siteId)`

---

## Service Layer

### `SecuritySettingsService`

Central service for all security operations. Inject as a singleton.

```
- getSettingsForSite(Site $site): array
    Returns all settings grouped by category

- applySetting(Site $site, string $category, string $key, array $value): void
    Updates security_settings table
    Creates a SecurityCommand for the agent to pick up

- applyPreset(SecurityPreset $preset, Collection $sites): void
    Applies all settings from preset to each site
    Creates batch of SecurityCommands

- syncSettingsFromAgent(Site $site, array $reportedSettings): void
    Agent reports what is actually applied; update applied_at or flag mismatches

- getSecurityScore(Site $site): int
    Calculate a 0-100 score based on how many recommended settings are enabled
    Weight critical settings higher (brute force = 15 pts, hide version = 5 pts)
```

### `SecurityCommandService`

```
- getPendingCommands(Site $site): Collection
    Returns pending commands ordered by priority, created_at

- processCommandResult(SecurityCommand $command, array $result): void
    Update command status
    Update corresponding SecuritySetting (applied_at or failed_at)
    Dispatch notification if critical failure

- cleanupStaleCommands(): void
    Reset commands stuck in 'picked_up' state (run via scheduler)

- createCommand(Site $site, string $category, string $action, array $payload, string $priority = 'normal'): SecurityCommand
```

### `SecurityActivityService`

```
- ingestLogs(Site $site, array $logs): void
    Bulk insert activity logs from agent

- getRecentActivity(Site $site, int $days = 7): Collection

- getFailedLoginStats(Site $site, int $days = 30): array
    Grouped by IP, with counts

- pruneOldLogs(int $retentionDays = 90): int
    Cleanup, run via scheduler
```

### `SecurityPresetService`

```
- createPreset(string $name, array $settings, ?string $description = null): SecurityPreset

- createFromSite(Site $site, string $name): SecurityPreset
    Snapshot current site config into a preset

- applyToSites(SecurityPreset $preset, array $siteIds): void
    For each site: update security_settings + create commands

- getPresetDiff(SecurityPreset $preset, Site $site): array
    Show what would change
```

---

## API Endpoints (Agent-Facing)

All agent endpoints are prefixed with `/api/agent/{site_token}/security/` and authenticated via the site agent token.

### `GET /pending-commands`

Returns pending commands for the site, ordered by priority and creation time.

```json
{
  "commands": [
    {
      "command_id": "uuid",
      "category": "hardening",
      "action": "apply_setting",
      "payload": {
        "setting_key": "disable_theme_editor",
        "value": {"enabled": true}
      },
      "priority": "high"
    }
  ]
}
```

### `POST /command-results`

Agent reports results for one or more commands.

```json
{
  "results": [
    {
      "command_id": "uuid",
      "status": "completed",
      "result": {
        "setting_key": "disable_theme_editor",
        "applied": true,
        "previous_value": false
      }
    },
    {
      "command_id": "uuid",
      "status": "failed",
      "result": {
        "setting_key": "custom_rules",
        "error": "Health check failed after applying .htaccess changes",
        "rolled_back": true
      }
    }
  ]
}
```

### `POST /activity-logs`

Agent uploads activity log entries in bulk.

```json
{
  "logs": [
    {
      "event_type": "failed_login",
      "username": "admin",
      "action": "login_failed",
      "ip_address": "185.234.xx.xx",
      "user_agent": "Mozilla/5.0...",
      "occurred_at": "2026-03-03T09:15:22Z"
    }
  ]
}
```

### `POST /sync-state`

Agent reports full current state (settings in effect + WordPress users). Called on agent activation and daily.

```json
{
  "settings": {
    "hardening": {
      "disable_theme_editor": {"enabled": true},
      "hide_wp_version": {"enabled": false}
    }
  },
  "users": [
    {
      "wp_user_id": 1,
      "username": "andrei",
      "email": "andrei@simplead.ro",
      "role": "administrator",
      "display_name": "Andrei"
    }
  ]
}
```

---

## Livewire Components (UI)

### Per-Site Security Page

Route: `/sites/{site}/security`

Tabs:

1. **Overview** — security score, quick status of all categories, last sync time, pending commands count
2. **Hardening** — toggles for all hardening + htaccess settings (combined into one view)
3. **Login Protection** — 2FA toggle, custom login URL, brute force config, IP whitelist
4. **Captcha** — provider selection, key configuration, form toggles
5. **Activity** — log table with filters (event type, IP, username, date range), search
6. **Users** — table of WordPress users with privileged roles
7. **IP Management** — whitelist/blocklist management, banned IPs list, auto-block config

### Component Architecture

```
Livewire\Security\SiteSecurityOverview     — main container, handles tab navigation
Livewire\Security\HardeningSettings        — toggle list for hardening + htaccess
Livewire\Security\LoginProtection          — login settings form
Livewire\Security\CaptchaSettings          — captcha configuration form
Livewire\Security\ActivityLog              — paginated log table with filters
Livewire\Security\SiteUsers                — user audit table
Livewire\Security\IpManagement             — IP whitelist/blocklist CRUD + banned IPs
```

### Setting Toggle Pattern

Every hardening toggle follows this consistent pattern:

- Toggle switch (Alpine.js for instant visual feedback)
- On toggle: Livewire calls `SecuritySettingsService->applySetting()`
- Service updates DB + creates command
- UI shows "Pending..." indicator next to the toggle
- When agent confirms (via polling or next page load): indicator changes to "Applied" with timestamp
- If agent reports failure: indicator shows "Failed" with error message + retry button

States for each setting: `not_configured` | `pending` | `applied` | `failed`

### Global Security Dashboard

Route: `/security`

- **Presets management** — CRUD for security presets, apply to multiple sites
- **Cross-site overview** — table of all sites with their security score, number of enabled settings, last sync
- **Bulk actions** — apply preset to selected sites, force re-sync, export security audit

### Preset Management Component

```
Livewire\Security\PresetManager
- List all presets with site count
- Create new preset (form with all setting categories)
- Create preset from existing site (snapshot)
- Apply preset to sites (multi-select sites -> show diff -> confirm -> apply)
- Edit preset
- Delete preset (with confirmation, detach from sites first)
```

---

## Design & UI Guidelines

Follow the existing SimpleAD Manager design system (WPMUDEV-inspired dark sidebar, purple/violet accents):

- **Toggles:** Green/lime accent for enabled state on dark backgrounds
- **Settings layout:** Center-aligned content area with max-width, grouped settings with clear category headers
- **Status indicators:** Applied = green dot/checkmark, Pending = yellow/amber pulsing dot, Failed = red dot with tooltip, Not configured = gray/muted
- **Tables:** Use existing table component style with pagination, search, and filters
- **Warning banners:** Yellow/amber background with icon for important notices (like multisite .htaccess warnings)
- **Tabs:** Horizontal tabs with underline indicator for active tab

---

## Security Presets — Detailed Behavior

### Preset Structure (JSONB)

```json
{
  "hardening": {
    "disable_theme_editor": {"enabled": true},
    "block_readme_access": {"enabled": true},
    "disable_user_enumeration": {"enabled": true},
    "hide_wp_version": {"enabled": true},
    "block_application_passwords": {"enabled": false},
    "restrict_xmlrpc": {"enabled": true},
    "restrict_rest_api": {"enabled": false}
  },
  "htaccess": {
    "security_headers": {"enabled": true, "headers": {"x_frame_options": "SAMEORIGIN"}},
    "block_default_files": {"enabled": true},
    "block_debug_log": {"enabled": true},
    "disable_directory_listing": {"enabled": true}
  },
  "login": {
    "brute_force_protection": {"enabled": true, "block_duration_minutes": 60, "max_attempts": 10, "window_minutes": 5}
  },
  "captcha": {
    "captcha_config": {"enabled": false}
  },
  "ip_management": {
    "firewall_enabled": {"enabled": true},
    "user_role_whitelist": {"roles": ["administrator", "editor"]},
    "auto_block_settings": {"block_duration_minutes": 1, "threshold": 60, "window_minutes": 1}
  },
  "activity_log": {
    "activity_log_config": {"enabled": true, "log_posts": false, "log_comments": false, "log_failed_logins": true, "upload_to_central": true}
  }
}
```

### Default Presets to Ship With (seeder)

**1. "Basic Protection" (recommended for most sites)**
- All hardening toggles ON except `restrict_rest_api` and `block_application_passwords`
- Security headers ON, block default files ON
- Brute force protection ON (60 min block, 10 attempts, 5 min window)
- Activity log ON (failed logins only, upload to central)
- Firewall ON

**2. "Maximum Security" (for high-value sites)**
- Everything from Basic, plus: `restrict_rest_api` ON, `block_application_passwords` ON
- Brute force stricter (120 min block, 5 attempts, 3 min window)
- All activity logging ON (posts, comments, failed logins)
- Auto-block threshold lower (30 requests, 1 min window)

**3. "Minimal / Monitoring Only"**
- All hardening toggles OFF
- Activity log ON (failed logins only, upload to central)
- Everything else OFF
- For sites where the client manages their own security but you want visibility

### Applying a Preset — Workflow

1. User selects a preset and one or more sites
2. System generates a **diff view**: for each site, show what would change (current -> new)
3. User confirms the changes
4. System updates `security_settings` table for each site
5. System creates `security_commands` in batch for each site
6. System creates/updates `security_preset_site` pivot entries
7. UI shows progress as agents pick up and execute commands

---

## Security Score Calculation

Each site gets a 0-100 score. Appears on per-site overview, global dashboard, and monthly PDF reports.

### Scoring Weights

| Setting | Points | Rationale |
|---|---|---|
| `brute_force_protection` | 15 | Critical: prevents credential stuffing |
| `disable_theme_editor` | 10 | Prevents code injection if admin is compromised |
| `restrict_xmlrpc` | 10 | Major attack vector |
| `security_headers` | 10 | Prevents clickjacking, XSS, MIME sniffing |
| `disable_user_enumeration` | 8 | Reduces reconnaissance surface |
| `block_default_files` | 7 | Prevents information disclosure |
| `hide_wp_version` | 5 | Minor but easy win |
| `block_readme_access` | 5 | Prevents version disclosure |
| `block_debug_log` | 5 | Prevents sensitive info exposure |
| `disable_directory_listing` | 5 | Prevents directory browsing |
| `block_application_passwords` | 5 | Reduces API attack surface |
| `restrict_rest_api` | 5 | Reduces API attack surface |
| `firewall_enabled` | 5 | Basic request filtering |
| `activity_log_config` (enabled + upload) | 5 | Visibility and audit trail |
| **Total** | **100** | |

A setting only counts if status is `applied` (confirmed by agent), not just `pending`.

Score thresholds: 80-100 Green (Good), 50-79 Yellow (Needs Attention), 0-49 Red (At Risk).

---

## Scheduled Tasks

```php
// Clean up stale commands (stuck in 'picked_up' for >30 minutes)
Schedule::call(fn() => app(SecurityCommandService::class)->cleanupStaleCommands())
    ->everyFifteenMinutes();

// Prune old activity logs (keep 90 days)
Schedule::call(fn() => app(SecurityActivityService::class)->pruneOldLogs(90))
    ->daily();

// Clean up expired banned IPs
Schedule::command('security:cleanup-banned-ips')->hourly();

// Recalculate security scores for all sites (cached value)
Schedule::command('security:recalculate-scores')->dailyAt('06:00');
```

---

## Implementation Order

### Phase 1: Foundation (Week 1-2)
1. Run migrations (all tables)
2. Create all Eloquent models with relationships and scopes
3. Create `SecuritySettingsService` and `SecurityCommandService`
4. Create agent-facing API endpoints (pending-commands, command-results, sync-state)
5. Create the basic per-site security overview page with tab navigation

### Phase 2: Hardening & Settings UI (Week 2-3)
6. Build `HardeningSettings` Livewire component (all hardening + htaccess toggles)
7. Build `LoginProtection` Livewire component
8. Build `CaptchaSettings` Livewire component
9. Build `IpManagement` Livewire component
10. Implement the pending/applied/failed status indicator pattern

### Phase 3: Activity & Users (Week 3-4)
11. Build activity log ingestion endpoint
12. Build `ActivityLog` Livewire component with filters and search
13. Build `SiteUsers` Livewire component
14. Implement `SecurityActivityService` with pruning

### Phase 4: Presets & Global Dashboard (Week 4-5)
15. Build `SecurityPresetService`
16. Build `PresetManager` Livewire component (CRUD)
17. Build preset diff view and bulk apply workflow
18. Build global security dashboard with cross-site overview
19. Create default preset seeder

### Phase 5: Scoring & Polish (Week 5-6)
20. Implement security score calculation
21. Add score to site overview cards and global dashboard
22. Add security section to PDF monthly reports (if applicable)
23. Testing and edge case handling

---

## Important Implementation Notes

1. **Always queue commands, never apply directly.** The central server never modifies WordPress sites directly. It only creates commands; the agent does the work.

2. **Idempotent commands.** If the same setting is toggled on/off/on quickly, the agent should only care about the latest state. When creating commands, cancel any pending commands for the same setting_key before creating a new one.

3. **Agent sync is the source of truth for what is actually applied.** The `security_settings` table tracks the desired state; the `applied_at` timestamp confirms reality. If there is a mismatch, flag it in the UI.

4. **Captcha keys are sensitive.** Store `secret_key` encrypted in the database. Only send to the specific site agent, never expose in the UI after initial setup (mask it).

5. **The .htaccess rollback mechanism is critical.** If the agent applies .htaccess rules that break the site, and cannot reach itself to verify, it should have a time-based fallback: revert after 60 seconds if no confirmation flag is set.

6. **Rate limit the activity log ingestion endpoint.** A compromised or misconfigured agent could flood the database. Accept max 1000 log entries per request, max 1 request per minute per site.

7. **Security presets should be versioned.** When a preset is edited, sites that have it applied should show a "preset updated, re-apply?" notification rather than auto-applying changes.

8. **Custom login URL must be stored securely.** If the agent fails to set it, the user could be locked out. Always keep a recovery mechanism (e.g., a wp-cli command or a file-based override).

9. **IP management should support CIDR notation.** Allow entries like `192.168.1.0/24` in whitelists and blocklists. Use PostgreSQL INET type which supports this natively.

10. **Consider multi-site WordPress installs.** Some settings (especially .htaccess) behave differently on WordPress Multisite. The agent should report if a site is multisite, and the UI should warn users about incompatible settings (similar to Patchstack's warning banner in the .htaccess tab).
