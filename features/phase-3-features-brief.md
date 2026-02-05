# Phase 3: Diagnostics & Infrastructure

Build these 3 features. Focus on error tracking, database health, and email configuration.

**Dependencies:** Phase 1 (notifications)

---

## Feature 9: Error Log Aggregation

**What it does:**
Centralizes PHP errors and WordPress debug logs from all managed sites. See all errors in one place.

**Requirements:**

1. **Database:**
   - `error_logs` table

2. **Error log entry has:**
   - Site ID
   - Error level (fatal, error, warning, notice, deprecated)
   - Message
   - File path
   - Line number
   - Stack trace (if available)
   - Context (JSON - request URL, user, etc.)
   - Count (how many times this exact error occurred)
   - First seen timestamp
   - Last seen timestamp
   - Is resolved (boolean)
   - Resolved by user ID
   - Resolved at timestamp

3. **WordPress Connector Plugin endpoint needed:**
   - `GET /simplead/v1/error-logs` - returns recent errors
   - Reads from debug.log file
   - Parses PHP error format
   - Returns structured data

4. **Service:**
   - `ErrorLogService`
   - Fetch errors from site
   - Parse and normalize error formats
   - Deduplicate (same error = increment count)
   - Store in database

5. **Job:**
   - `SyncErrorLogsJob`
   - Runs periodically (every 15 minutes?)
   - Fetches new errors from each site
   - Can be triggered manually

6. **UI (Livewire):**
   - Global errors page: `/errors` - shows errors from ALL sites
   - Site errors page: `/sites/{site}/errors` - filtered to one site
   - Table view:
     - Level (color coded: red=fatal, orange=error, yellow=warning)
     - Message (truncated, expandable)
     - Site name (on global view)
     - File:line
     - Count
     - Last seen
   - Filters:
     - By site
     - By level
     - By date range
     - Show/hide resolved
   - Actions:
     - Mark as resolved
     - Mark all as resolved
     - View full details (modal with stack trace)
   - Stats at top: X fatal, Y errors, Z warnings

7. **Notifications:**
   - Alert on new fatal errors
   - Use event: `fatal_error_detected`
   - Don't spam - group/throttle notifications

---

## Feature 10: Database Health

**What it does:**
Monitor database performance: table sizes, overhead, engine types. Identify optimization opportunities.

**Requirements:**

1. **Database:**
   - `database_health_checks` table

2. **Health check has:**
   - Site ID
   - Total database size (bytes)
   - Total tables count
   - Tables data (JSON array):
     - Table name
     - Engine (InnoDB, MyISAM)
     - Rows count
     - Data size
     - Index size
     - Overhead (fragmentation)
   - Largest tables (top 10)
   - Tables with overhead
   - MyISAM tables count (should be 0 for modern WP)
   - Autoload data size (from wp_options)
   - Status: healthy, warning, critical
   - Checked timestamp

3. **WordPress Connector Plugin endpoint needed:**
   - `GET /simplead/v1/database-health`
   - Runs SHOW TABLE STATUS
   - Calculates sizes and overhead
   - Checks autoload options size

4. **Service:**
   - `DatabaseHealthService`
   - Fetch health data from site
   - Analyze and determine status
   - Store results

5. **Thresholds:**
   - Database size > 1GB: warning
   - Table overhead > 100MB total: warning
   - Autoload size > 1MB: warning
   - Any MyISAM tables: warning
   - Single table > 500MB: warning

6. **UI (Livewire):**
   - Site database page or health section
   - Overview cards:
     - Total size (formatted: "245 MB")
     - Tables count
     - Health status (colored badge)
   - Issues panel (if any):
     - "X tables have overhead - consider optimizing"
     - "Autoload data is large (X MB)"
     - "X tables using MyISAM engine"
   - Tables list (sortable):
     - Table name
     - Engine
     - Rows
     - Size
     - Overhead
   - "Refresh" button
   - Last checked timestamp
   - Link to Database Cleanup feature

---

## Feature 11: Email Deliverability Check

**What it does:**
Checks SPF, DKIM, DMARC records and blacklist status. Ensures emails from the site will be delivered.

**Requirements:**

1. **Database:**
   - `email_health_checks` table

2. **Email health check has:**
   - Site ID
   - Domain checked
   - SPF: exists (bool), record text, status (valid/invalid/missing), issues
   - DKIM: exists (bool), selector found, status
   - DMARC: exists (bool), record text, policy (none/quarantine/reject), status
   - Blacklists checked (JSON array: name, listed bool)
   - Blacklists clean count
   - Blacklists listed count
   - MX records (JSON array: priority, host)
   - Overall score (0-100)
   - Status: excellent, good, warning, critical
   - Checked timestamp

3. **Service:**
   - `EmailDeliverabilityService`
   - Check SPF: DNS TXT record starting with "v=spf1"
   - Check DMARC: DNS TXT record at _dmarc.domain
   - Check DKIM: Try common selectors (default, google, selector1, selector2, k1, mail)
   - Check blacklists: Query DNS blacklists (zen.spamhaus.org, bl.spamcop.net, etc.)
   - Get MX records
   - Calculate score

4. **Score calculation:**
   - SPF valid: +33 points
   - DMARC valid: +34 points
   - DKIM found: +33 points
   - Each blacklist listing: -20 points
   - Status based on score: 90+ excellent, 70+ good, 50+ warning, below critical

5. **Blacklists to check:**
   - zen.spamhaus.org
   - bl.spamcop.net
   - b.barracudacentral.org
   - dnsbl.sorbs.net
   - (5-6 major ones, not too many)

6. **UI (Livewire):**
   - Site email or DNS section
   - Score display (big number with color)
   - Status cards for each check:
     - SPF: ✓ Valid / ✗ Missing / ⚠ Invalid
     - DKIM: ✓ Found / ✗ Not found
     - DMARC: ✓ Valid (policy: reject) / ⚠ Valid (policy: none) / ✗ Missing
   - Blacklist status: "Clean on 5/5 lists" or "Listed on X lists" with details
   - MX records table (priority, host)
   - Recommendations panel:
     - "Add SPF record" if missing
     - "Set up DMARC" if missing
     - "DMARC policy is 'none' - consider 'quarantine' or 'reject'"
   - "Check Now" button
   - Last checked timestamp

7. **Notifications:**
   - Alert if newly listed on blacklist
   - Use event: `email_blacklisted`

---

## Implementation Order

1. **Error Log Aggregation** - very useful, needs connector endpoint
2. **Database Health** - useful insights, needs connector endpoint
3. **Email Deliverability** - DNS-based, no connector needed

## Connector Plugin Updates

New endpoints needed:

- `/simplead/v1/error-logs` - read and parse debug.log
- `/simplead/v1/database-health` - run SHOW TABLE STATUS and analyze
