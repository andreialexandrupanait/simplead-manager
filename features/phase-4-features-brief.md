# Phase 4: Security Module

Complete security system inspired by WPMUDEV Defender. This is the largest feature set.

**Dependencies:** Phase 1 (notifications), Phase 2 (core integrity, plugin checks)

---

## Feature 12: Security Module

**What it does:**
Comprehensive security monitoring: scoring, recommendations, firewall, login protection, audit logs, vulnerability scanning.

### Part A: Security Score & Dashboard

**Requirements:**

1. **Database:**
   - `security_scans` table - scan history
   - `security_issues` table - detected issues
   - `security_settings` table - per-site settings

2. **Security scan has:**
   - Site ID
   - Overall score (0-100)
   - Scores breakdown (JSON): core_integrity, plugins, firewall, login, headers, ssl
   - Issues found count by severity (critical, high, medium, low)
   - Scan duration
   - Scanned timestamp

3. **Security issue has:**
   - Site ID
   - Scan ID
   - Category (core, plugin, theme, config, header, login)
   - Type (specific issue identifier)
   - Severity (critical, high, medium, low)
   - Title
   - Description
   - Recommendation
   - Is fixed (boolean)
   - Is ignored (boolean)
   - First detected
   - Fixed at

4. **Score calculation:**
   - Start at 100
   - Critical issue: -20 each
   - High issue: -10 each
   - Medium issue: -5 each
   - Low issue: -2 each
   - Minimum score: 0

5. **UI:**
   - Security dashboard per site
   - Big score circle (color: green 80+, yellow 50-79, red <50)
   - Breakdown by category (mini scores)
   - Issues list grouped by severity
   - "Scan Now" button
   - Last scan timestamp
   - "Fix" buttons for auto-fixable issues
   - "Ignore" option for acknowledged issues

---

### Part B: Security Recommendations (Hardening)

**What it does:**
Checklist of WordPress security best practices with one-click fixes.

**Recommendations to check:**

1. **File Security:**
   - Disable file editor in wp-admin
   - Prevent PHP execution in uploads
   - Protect wp-config.php
   - Protect .htaccess
   - Hide WordPress version
   - Disable directory listing

2. **Login Security:**
   - Change default admin username
   - Strong password policy
   - Limit login attempts
   - Disable XML-RPC (if not needed)
   - Disable trackbacks/pingbacks

3. **Database Security:**
   - Change default table prefix (if wp_)
   - Remove unused database tables

4. **HTTP Headers:**
   - X-Frame-Options
   - X-Content-Type-Options
   - X-XSS-Protection
   - Referrer-Policy
   - Permissions-Policy
   - Content-Security-Policy (basic)

5. **SSL/HTTPS:**
   - Force HTTPS
   - HSTS header
   - Secure cookies

**Database:**
- `security_recommendations` table
  - Site ID
  - Recommendation key
  - Status: passed, failed, ignored
  - Can auto-fix (boolean)
  - Last checked

**WordPress Connector endpoints needed:**
- `GET /simplead/v1/security-check` - checks all recommendations
- `POST /simplead/v1/security-fix` - applies a fix (by key)

**UI:**
- Recommendations checklist
- Each item shows: ✓/✗ status, title, description
- "Fix" button for auto-fixable items
- "Ignore" option
- Progress bar (X of Y secured)

---

### Part C: Vulnerability Database

**What it does:**
Checks plugins and themes against known vulnerabilities.

**How it works:**
- Use WPScan Vulnerability Database API or similar
- Check each plugin/theme version against known CVEs
- Alert when vulnerabilities found

**Database:**
- `vulnerability_alerts` table
  - Site ID
  - Plugin/theme slug
  - Installed version
  - Vulnerability ID
  - Severity (low, medium, high, critical)
  - Title
  - Description
  - Fixed in version
  - References (URLs)
  - Status: active, fixed, ignored
  - Detected at

**Service:**
- `VulnerabilityService`
- Check plugins/themes against vulnerability database
- Create alerts for found vulnerabilities
- Mark as fixed when updated past fixed_in version

**UI:**
- Vulnerabilities panel on security page
- List of active vulnerabilities (sorted by severity)
- Each shows: plugin name, version, vulnerability title, severity
- "Update Now" button if fix available
- "Ignore" option
- History of past vulnerabilities

**Notifications:**
- Alert on new critical/high vulnerabilities
- Use event: `vulnerability_detected`

---

### Part D: Audit Log

**What it does:**
Tracks all user actions on the WordPress site. Who did what, when.

**Database:**
- `audit_logs` table
  - Site ID
  - WordPress user ID
  - WordPress username
  - User role
  - Action type (login, logout, post_created, post_updated, plugin_activated, etc.)
  - Object type (post, page, plugin, theme, user, option)
  - Object ID
  - Object title
  - Old value (JSON, for changes)
  - New value (JSON, for changes)
  - IP address
  - User agent
  - Timestamp

**Actions to track:**
- User: login, logout, failed_login, created, updated, deleted
- Post/Page: created, updated, trashed, deleted, published
- Plugin: activated, deactivated, updated, installed, deleted
- Theme: switched, updated, installed, deleted
- Option: changed (important options only)
- Media: uploaded, deleted
- Core: updated

**WordPress Connector:**
- Hooks into WordPress actions to log events
- Stores locally, syncs to manager
- `GET /simplead/v1/audit-logs` - fetch recent logs

**Service:**
- `AuditLogService`
- Sync logs from site
- Query and filter logs

**UI:**
- Audit log page per site
- Table: timestamp, user, action, object, IP
- Filters: by user, by action type, by date range
- Search
- Export CSV
- Retention setting (how long to keep logs)

---

### Part E: IP Management (Basic Firewall)

**What it does:**
Block or allow specific IP addresses. Track blocked attempts.

**Database:**
- `ip_rules` table
  - Site ID (nullable for global rules)
  - IP address or range (CIDR notation)
  - Type: allow, block
  - Reason
  - Expires at (nullable)
  - Created by user ID
  - Hits count
  - Last hit at
  - Created at

- `blocked_requests` table
  - Site ID
  - IP address
  - Rule ID (which rule blocked it)
  - Request URL
  - User agent
  - Blocked at

**WordPress Connector:**
- Check IP against rules on each request
- Block if matched
- Log blocked attempts
- `GET /simplead/v1/ip-rules` - get rules
- `POST /simplead/v1/ip-rules` - add rule
- `DELETE /simplead/v1/ip-rules/{id}` - remove rule
- `GET /simplead/v1/blocked-requests` - get blocked attempts

**UI:**
- IP Management page
- Tabs: Blocklist, Allowlist
- Add IP form: IP/range, reason, expiry (optional)
- List of rules with hit counts
- Recent blocked requests log
- Import/export rules

---

## Implementation Order

1. **Security Score & Dashboard** - foundation, uses existing checks
2. **Security Recommendations** - extends dashboard
3. **Vulnerability Database** - needs external API integration
4. **Audit Log** - needs connector hooks
5. **IP Management** - needs connector middleware

## Connector Plugin Updates

Major updates needed:
- Security check endpoint (all recommendations)
- Security fix endpoint
- Audit log hooks and sync endpoint
- IP firewall middleware and endpoints
- Vulnerability check integration

## External APIs

- WPScan Vulnerability Database (or Wordfence Intelligence API)
- Consider rate limits and caching
