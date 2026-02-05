# Phase 6: Updates & Rollback

Features for safer WordPress updates.

**Dependencies:** Backup system (already implemented)

---

## Feature 15: Rollback Functionality

**What it does:**
Quickly revert plugins, themes, or WordPress core to previous versions after a bad update.

**Requirements:**

1. **Database:**
   - `rollback_points` table
     - Site ID
     - Type: plugin, theme, core
     - Slug (for plugin/theme)
     - From version
     - To version
     - Backup reference (link to backup or stored files)
     - Status: available, used, expired
     - Created at
     - Expires at

2. **How it works:**
   - Before each update, store the current version files
   - Or: take a quick backup before bulk updates
   - On rollback: restore files from rollback point
   - For plugins/themes: can download old version from WP.org if available

3. **WordPress Connector endpoints:**
   - `POST /simplead/v1/rollback/plugin` - rollback plugin to version
   - `POST /simplead/v1/rollback/theme` - rollback theme to version
   - `POST /simplead/v1/rollback/core` - rollback WordPress core (dangerous, needs care)
   - `GET /simplead/v1/rollback/available` - list available rollback points

4. **Service:**
   - `RollbackService`
   - Create rollback point before update
   - Execute rollback
   - Clean expired rollback points

5. **UI:**
   - After failed update: "Rollback to previous version" button
   - Site updates page: rollback option per item
   - Confirmation modal with warnings
   - History of rollbacks performed

6. **Safety:**
   - Recommend backup before rollback
   - Core rollbacks should be rare/discouraged
   - Clear warnings about potential issues
   - Test site after rollback

---

## Feature 16: Update Testing Mode

**What it does:**
Test updates on a staging copy before applying to production.

**Requirements:**

1. **Concept:**
   - Create temporary staging site (clone)
   - Apply updates to staging
   - Run automated tests (site loads, no PHP errors)
   - If tests pass, apply to production
   - Delete staging

2. **Database:**
   - `update_tests` table
     - Site ID
     - Staging URL (temporary)
     - Updates to test (JSON: list of plugins/themes/core)
     - Status: creating_staging, testing, passed, failed, applying, completed
     - Test results (JSON: each check result)
     - Errors (if any)
     - Started at
     - Completed at

3. **How it works:**
   - User selects updates to test
   - System creates staging clone (needs hosting support or WP staging plugin)
   - Applies updates to staging
   - Runs checks:
     - Homepage loads (HTTP 200)
     - No PHP fatal errors
     - Admin loads
     - Key pages load
   - Reports results
   - User decides to apply or not

4. **Limitations:**
   - Requires staging capability (hosting or plugin)
   - Complex to implement fully
   - Consider simplified version first

5. **Simplified version (Phase 1):**
   - "Safe Update" mode
   - Takes backup before update
   - Applies update
   - Runs quick health checks
   - Auto-rollback if checks fail
   - No separate staging needed

6. **UI:**
   - Update page: "Test Update" option
   - Test progress indicator
   - Results report
   - "Apply to Production" or "Cancel" buttons

---

# Phase 7: Advanced Monitoring

Additional monitoring capabilities.

**Dependencies:** Phase 1 (notifications)

---

## Feature 17: Resource Usage Monitoring

**What it does:**
Monitor server resources: CPU, RAM, disk space.

**Requirements:**

1. **Database:**
   - `resource_checks` table
     - Site ID
     - CPU usage percentage
     - Memory used (bytes)
     - Memory total (bytes)
     - Memory percentage
     - Disk used (bytes)
     - Disk total (bytes)
     - Disk percentage
     - Load average (1, 5, 15 min)
     - Checked at

2. **How it works:**
   - WordPress Connector reads server stats
   - Not all hosts allow this (shared hosting limitations)
   - Works best on VPS/dedicated servers

3. **WordPress Connector endpoint:**
   - `GET /simplead/v1/server-resources`
   - Uses PHP functions: sys_getloadavg(), memory_get_usage(), disk_free_space()
   - May need shell_exec for some stats (if allowed)

4. **Service:**
   - `ResourceMonitorService`
   - Fetch resource stats
   - Store historical data
   - Calculate trends

5. **Thresholds:**
   - Disk > 90%: critical
   - Disk > 80%: warning
   - Memory > 90%: critical
   - Memory > 80%: warning
   - CPU sustained > 80%: warning

6. **UI:**
   - Site resources page or dashboard widget
   - Gauges: CPU, Memory, Disk
   - Historical charts
   - Alerts for thresholds
   - "Not available" message for shared hosting

7. **Notifications:**
   - Alert when thresholds exceeded
   - Use events: `disk_space_critical`, `memory_critical`

---

## Feature 18: WooCommerce Monitoring

**What it does:**
Track WooCommerce sales, orders, and inventory for e-commerce sites.

**Requirements:**

1. **Database:**
   - `woocommerce_stats` table
     - Site ID
     - Date
     - Orders count
     - Revenue (total)
     - Currency
     - Average order value
     - Products sold count
     - Refunds count
     - Refunds amount
     - New customers
     - Returning customers

   - `woocommerce_alerts` table
     - Site ID
     - Type: low_stock, out_of_stock, failed_order, high_refunds
     - Product ID (if applicable)
     - Product name
     - Message
     - Is acknowledged
     - Created at

2. **WordPress Connector endpoints:**
   - `GET /simplead/v1/woo/stats` - daily/weekly/monthly stats
   - `GET /simplead/v1/woo/orders` - recent orders
   - `GET /simplead/v1/woo/low-stock` - products with low stock
   - `GET /simplead/v1/woo/out-of-stock` - products out of stock

3. **Service:**
   - `WooCommerceService`
   - Fetch stats and sync
   - Check for alerts (low stock, etc.)

4. **Detection:**
   - Automatically detect if site has WooCommerce
   - Only show WooCommerce features for WC sites

5. **UI:**
   - Site WooCommerce page (only for WC sites)
   - Stats cards: Today's revenue, Orders, Average order
   - Revenue chart (last 30 days)
   - Recent orders table
   - Alerts panel:
     - Low stock warnings
     - Out of stock products
   - Settings: stock thresholds

6. **Notifications:**
   - Daily sales summary (optional)
   - Low stock alerts
   - Out of stock alerts
   - Use events: `woo_low_stock`, `woo_out_of_stock`

---

## Feature 19: SEO Monitoring

**What it does:**
Track SEO health: rankings, meta tags, indexing status.

**Requirements:**

1. **Database:**
   - `seo_checks` table
     - Site ID
     - Homepage title
     - Homepage meta description
     - Has sitemap (boolean)
     - Sitemap URL
     - Sitemap pages count
     - Has robots.txt (boolean)
     - Robots.txt issues
     - Open Graph tags present
     - Twitter cards present
     - Schema markup present
     - Indexability issues (JSON)
     - Score (0-100)
     - Checked at

   - `seo_rankings` table (optional, requires external API)
     - Site ID
     - Keyword
     - Position
     - URL ranking
     - Search engine
     - Tracked at

2. **What to check (without external API):**
   - Homepage has title tag
   - Homepage has meta description
   - Title length (50-60 chars ideal)
   - Description length (150-160 chars ideal)
   - Sitemap exists and is valid XML
   - Robots.txt exists
   - Robots.txt doesn't block important pages
   - Open Graph tags present
   - Twitter card tags present
   - Canonical URLs set
   - No noindex on important pages
   - Image alt tags (sample check)
   - Heading structure (H1 present, hierarchy)

3. **WordPress Connector endpoint:**
   - `GET /simplead/v1/seo-check` - comprehensive SEO audit

4. **Service:**
   - `SeoService`
   - Fetch SEO data
   - Score calculation
   - Generate recommendations

5. **Score calculation:**
   - Title present and good length: +10
   - Description present and good length: +10
   - Sitemap exists: +15
   - Robots.txt proper: +10
   - OG tags: +10
   - Schema markup: +15
   - No indexability issues: +20
   - Headings structure: +10

6. **UI:**
   - Site SEO page
   - Score display
   - Checklist of items (pass/fail)
   - Recommendations for improvements
   - Sitemap status
   - Meta tags preview (how it looks in Google)
   - "Check Now" button

7. **Optional: Rank tracking:**
   - Requires external API (SerpApi, etc.)
   - User adds keywords to track
   - Daily/weekly position checks
   - Historical ranking chart
   - Can be Phase 2 of SEO feature

---

## Implementation Order

### Phase 6:
1. **Rollback Functionality** - builds on existing backup system
2. **Update Testing Mode** - complex, maybe simplified version first

### Phase 7:
1. **Resource Usage** - straightforward if hosting allows
2. **WooCommerce Monitoring** - niche, only for WC sites
3. **SEO Monitoring** - useful for all sites

## Notes

- Update testing is complex - consider simpler "safe update with auto-rollback" first
- Resource monitoring depends heavily on hosting environment
- WooCommerce features should be hidden for non-WC sites
- SEO rank tracking needs external API subscription
