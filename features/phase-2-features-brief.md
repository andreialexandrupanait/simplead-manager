# Phase 2: WordPress Health Checks

Build these 5 features in order. These detect issues with WordPress core, plugins, and scheduled tasks.

**Dependencies:** Phase 1 (notifications system)

---

## Feature 4: WordPress Core File Integrity

**What it does:**
Checks if WordPress core files have been modified compared to original WordPress.org files. Detects hacked/compromised sites.

**Requirements:**

1. **Database:**
   - `core_file_checks` table

2. **Core file check has:**
   - Site ID
   - WordPress version detected
   - Total files checked
   - Modified files count
   - Missing files count
   - Unknown files count (files in wp-admin/wp-includes that shouldn't exist)
   - Modified list (JSON: file path, expected hash, actual hash)
   - Missing list (JSON: file paths)
   - Unknown list (JSON: file paths)
   - Status: clean, modified, error
   - Error message (if failed)
   - Checked timestamp

3. **How it works:**
   - WordPress.org API provides checksums: `https://api.wordpress.org/core/checksums/1.0/?version=X.X.X&locale=en_US`
   - Call the site's connector plugin endpoint to get file hashes
   - Compare against official checksums
   - Report differences

4. **WordPress Connector Plugin endpoint needed:**
   - `GET /simplead/v1/core-integrity-check`
   - Returns: version, list of files with their MD5 hashes
   - Checks wp-admin and wp-includes directories
   - Reports modified, missing, and unknown files

5. **Service:**
   - `CoreFileIntegrityService`
   - Fetch official checksums from WordPress.org API
   - Call site connector to get actual file status
   - Compare and store results

6. **UI (Livewire):**
   - Site security tab or dedicated section
   - Status card: Clean ✓ or Modified ✗ with counts
   - Last checked timestamp
   - "Check Now" button
   - If issues found, expandable lists:
     - Modified files (show file path, highlight critical files like wp-config.php)
     - Missing files
     - Unknown files (potential malware)
   - Severity indicator (critical if core files modified)

7. **Notifications:**
   - Alert if status changes from clean to modified
   - Use event: `core_files_modified`

---

## Feature 5: Abandoned Plugin Detection

**What it does:**
Detects plugins that haven't been updated in 2+ years or have been removed from WordPress.org. These are security risks.

**Requirements:**

1. **Database:**
   - Add columns to existing `site_plugins` table (or create if doesn't exist):
     - `wp_org_last_updated` (timestamp)
     - `is_on_wp_org` (boolean)
     - `is_abandoned` (boolean) - no update in 2+ years
     - `is_closed` (boolean) - removed from WP.org
     - `closed_reason` (string: security, guideline, author-request)
     - `abandoned_checked_at` (timestamp)

2. **How it works:**
   - WordPress.org Plugin API: `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=SLUG`
   - Check each plugin's slug against API
   - If 404: plugin not on WP.org or removed
   - If found: check `last_updated` field
   - Abandoned = last_updated > 2 years ago

3. **Service:**
   - `PluginAbandonmentService`
   - Check single plugin against WP.org API
   - Check all plugins for a site (with rate limiting - 1 req/sec)
   - Update plugin records with findings

4. **Job:**
   - `CheckAbandonedPluginsJob`
   - Runs for a site, checks all plugins
   - Rate limited to respect WordPress.org API

5. **UI (Livewire):**
   - Site plugins page or security section
   - Warning banner if abandoned/closed plugins found
   - Plugin list shows badges:
     - 🔴 "Closed" - removed from WP.org
     - 🟠 "Abandoned" - no updates in 2+ years
   - Filter to show only problematic plugins
   - Last checked timestamp
   - "Check Now" button

6. **Notifications:**
   - Alert when abandoned or closed plugins detected
   - Use event: `abandoned_plugins_found`

---

## Feature 6: Plugin Conflict Detection

**What it does:**
Detects known plugin conflicts and incompatibilities. Warns about combinations that cause issues.

**Requirements:**

1. **Database:**
   - `plugin_conflicts` table (reference data - known conflicts)
   - `site_plugin_conflicts` table (detected conflicts per site)

2. **Known conflicts table:**
   - Plugin A slug
   - Plugin B slug (or "any" for plugins that conflict with many)
   - Conflict type: breaks_site, performance, functionality, security
   - Description of the conflict
   - Severity: low, medium, high, critical
   - Source URL (documentation)

3. **Site conflicts table:**
   - Site ID
   - Plugin A (from site's plugins)
   - Plugin B (from site's plugins)
   - Conflict ID (reference to known conflict)
   - Status: active, dismissed
   - Detected timestamp

4. **Service:**
   - `PluginConflictService`
   - Load known conflicts database
   - Check site's active plugins against known conflicts
   - Also check for duplicate functionality (2 SEO plugins, 2 caching plugins, etc.)

5. **Seeder:**
   - Seed common known conflicts:
     - Multiple caching plugins
     - Multiple SEO plugins
     - WooCommerce + incompatible themes
     - Elementor + conflicting plugins
     - Security plugins that conflict
   - Include ~20-30 common conflicts

6. **UI (Livewire):**
   - Site plugins page or health section
   - Conflict warnings panel
   - Each conflict shows: both plugins, severity, description
   - "Dismiss" button (user acknowledges)
   - Link to more info if available

7. **Notifications:**
   - Alert when new critical conflict detected
   - Use event: `plugin_conflict_detected`

---

## Feature 7: Cron Job Manager

**What it does:**
View, manage, and run WordPress scheduled tasks (cron jobs).

**Requirements:**

1. **Database:**
   - `site_cron_jobs` table

2. **Cron job has:**
   - Site ID
   - Hook name (wp_scheduled_delete, wp_update_plugins, etc.)
   - Schedule (hourly, daily, twicedaily, weekly, or custom interval)
   - Interval in seconds
   - Next run timestamp
   - Last run timestamp
   - Arguments (JSON)
   - Is disabled by user (boolean)

3. **WordPress Connector Plugin endpoints needed:**
   - `GET /simplead/v1/cron-list` - returns all scheduled cron jobs
   - `POST /simplead/v1/cron-run` - manually run a cron job
   - `POST /simplead/v1/cron-disable` - unschedule a cron job
   - `POST /simplead/v1/cron-enable` - re-schedule a cron job

4. **Service:**
   - `CronManagerService`
   - Sync cron jobs from site
   - Run job manually
   - Disable/enable jobs

5. **UI (Livewire):**
   - Site tools page or dedicated section
   - Table of cron jobs:
     - Hook name (with friendly description for known hooks)
     - Schedule (human readable: "Every hour", "Daily", etc.)
     - Next run (relative time)
     - Last run
     - Status (active/disabled)
   - Actions per job:
     - "Run Now" button
     - "Disable" / "Enable" toggle
   - "Sync" button to refresh from site
   - Show overdue jobs (next_run in past) with warning
   - Known hooks to show friendly names:
     - wp_scheduled_delete → "Empty Trash"
     - wp_update_plugins → "Check Plugin Updates"
     - wp_update_themes → "Check Theme Updates"
     - wp_version_check → "Check WordPress Updates"
     - wp_site_health_scheduled_check → "Site Health Check"

---

## Feature 8: Database Cleanup

**What it does:**
Clean up database bloat: post revisions, spam comments, transients, orphaned data.

**Requirements:**

1. **Database:**
   - `database_cleanups` table (history of cleanups)

2. **Cleanup history has:**
   - Site ID
   - Counts of deleted items (revisions, auto_drafts, trash_posts, spam_comments, trash_comments, transients, orphaned_meta)
   - Space saved in bytes
   - Status: completed, failed
   - Error message
   - Cleaned timestamp

3. **WordPress Connector Plugin endpoints needed:**
   - `GET /simplead/v1/db-cleanup-stats` - returns counts of cleanable items
   - `POST /simplead/v1/db-cleanup-run` - performs cleanup with options

4. **Cleanable items:**
   - Post revisions
   - Auto-draft posts
   - Trashed posts
   - Spam comments
   - Trashed comments
   - Expired transients
   - Orphaned post meta
   - Orphaned comment meta

5. **Service:**
   - `DatabaseCleanupService`
   - Get cleanup stats (preview)
   - Run cleanup with selected options
   - Store results

6. **UI (Livewire):**
   - Site tools page or database section
   - Stats cards showing counts:
     - "X revisions" 
     - "X spam comments"
     - "X transients"
     - etc.
   - Checkboxes to select what to clean
   - "Preview" shows what will be deleted
   - "Clean Now" button with confirmation
   - History table of past cleanups
   - Show space saved

7. **Safety:**
   - Always show confirmation before cleanup
   - Recommend backup before major cleanup
   - Don't auto-schedule cleanups (user-initiated only for now)

---

## Implementation Order

1. **Core File Integrity** - independent, high security value
2. **Abandoned Plugin Detection** - independent, uses WP.org API
3. **Plugin Conflict Detection** - depends on having plugins synced
4. **Cron Job Manager** - needs connector plugin endpoints
5. **Database Cleanup** - needs connector plugin endpoints

## Connector Plugin Updates

Features 4, 7, 8 require new endpoints in the WordPress connector plugin. Create these endpoints:

- `/simplead/v1/core-integrity-check`
- `/simplead/v1/cron-list`
- `/simplead/v1/cron-run`
- `/simplead/v1/cron-disable`
- `/simplead/v1/cron-enable`
- `/simplead/v1/db-cleanup-stats`
- `/simplead/v1/db-cleanup-run`

All endpoints require API key authentication (existing pattern).
