# Phase 5: External Integrations

Build Cloudflare integration and Public Status Page.

**Dependencies:** Phase 1 (notifications), Phase 3 (DNS viewer)

---

## Feature 13: Cloudflare Integration

**What it does:**
Manage Cloudflare settings from SimpleAd Manager: DNS, cache, firewall, analytics.

### Part A: Connection Setup

**Requirements:**

1. **Database:**
   - `cloudflare_connections` table
     - User ID
     - API token (encrypted)
     - Account ID
     - Account email
     - Is valid (boolean)
     - Last validated at
     - Created at

   - `site_cloudflare` table (link site to CF zone)
     - Site ID
     - Cloudflare connection ID
     - Zone ID
     - Zone name
     - Plan type (free, pro, business, enterprise)
     - Status (active, pending, moved)
     - Is paused
     - SSL mode (off, flexible, full, full_strict)
     - Cache level
     - Connected at

2. **Cloudflare API:**
   - Use API token (not global key) - more secure
   - Required permissions: Zone.Zone, Zone.DNS, Zone.Cache Purge, Zone.Firewall
   - Base URL: `https://api.cloudflare.com/client/v4`

3. **Service:**
   - `CloudflareService`
   - Validate API token
   - List zones
   - Get zone details
   - Connect site to zone

4. **UI:**
   - Settings page: Cloudflare Connections
   - Add connection: API token input, validate, save
   - List connected zones
   - Per-site: connect to Cloudflare zone (select from dropdown)

---

### Part B: DNS Management

**What it does:**
View and edit DNS records in Cloudflare.

**Service methods:**
- List DNS records for zone
- Create DNS record
- Update DNS record
- Delete DNS record

**UI:**
- Site Cloudflare page → DNS tab
- Table of records (type, name, content, TTL, proxied status)
- Add record form
- Edit record (inline or modal)
- Delete record (with confirmation)
- Toggle proxy status (orange cloud on/off)
- Import/export DNS records

---

### Part C: Cache Management

**What it does:**
Purge Cloudflare cache from the manager.

**Service methods:**
- Purge everything
- Purge by URL(s)
- Purge by cache tags
- Purge by prefix

**UI:**
- Site Cloudflare page → Cache tab
- "Purge Everything" button (with warning)
- Purge specific URLs form (textarea, one per line)
- Recent purge history
- Cache status/settings overview

---

### Part D: Firewall & Security

**What it does:**
View and manage Cloudflare firewall rules and security settings.

**Service methods:**
- Get security level
- Set security level (off, essentially_off, low, medium, high, under_attack)
- List firewall rules
- Create/update/delete firewall rules
- Get WAF status
- Block IP via Cloudflare

**UI:**
- Site Cloudflare page → Security tab
- Security level selector
- "Under Attack Mode" toggle
- Firewall rules list
- Add/edit firewall rule
- Integration with IP Management (block in CF)

---

### Part E: Analytics

**What it does:**
Show Cloudflare traffic analytics.

**Service methods:**
- Get analytics (requests, bandwidth, threats blocked, unique visitors)
- Time range options: 24h, 7d, 30d

**UI:**
- Site Cloudflare page → Analytics tab
- Stats cards: Total requests, Bandwidth, Threats blocked, Unique visitors
- Requests chart over time
- Breakdown by country (top 10)
- Cached vs uncached requests

---

## Feature 14: Public Status Page

**What it does:**
Public-facing page showing uptime status for client sites.

**Requirements:**

1. **Database:**
   - `status_pages` table
     - User ID
     - Client ID (optional)
     - Slug (URL-friendly, unique)
     - Title
     - Description
     - Logo URL
     - Primary color
     - Custom domain (optional)
     - Is public (boolean)
     - Show uptime percentage
     - Show response time
     - Show incident history
     - Incident history days (default 90)
     - Password hash (for protected pages)
     - Created/updated at

   - `status_page_sites` table (which sites appear)
     - Status page ID
     - Site ID
     - Display name (override)
     - Sort order
     - Is visible

   - `status_page_incidents` table
     - Status page ID
     - Site ID (optional)
     - Title
     - Description
     - Status: investigating, identified, monitoring, resolved
     - Severity: minor, major, critical
     - Is scheduled (for maintenance)
     - Scheduled start/end
     - Started at
     - Resolved at

   - `status_page_incident_updates` table
     - Incident ID
     - Status
     - Message
     - Created at

2. **Public Controller:**
   - No auth required (public pages)
   - Show page by slug or custom domain
   - Password protection if configured
   - JSON API endpoint for integrations

3. **Public UI:**
   - Clean, standalone page (no app layout)
   - Logo and title at top
   - Overall status banner (operational / degraded / outage)
   - List of monitored sites with status
   - Uptime percentage (if enabled)
   - Response time (if enabled)
   - Scheduled maintenance notices
   - Incident history (expandable by day)
   - "Powered by SimpleAd Manager" footer

4. **Admin UI (Livewire):**
   - Status Pages list page
   - Create/edit status page:
     - Basic info (title, description, slug)
     - Branding (logo, color)
     - Sites to include (checkbox list)
     - Display settings
     - Password protection
     - Custom domain instructions
   - Incidents management:
     - Create incident
     - Update incident status
     - Resolve incident
     - Schedule maintenance

5. **Automatic incidents:**
   - When site goes down (from uptime monitoring), offer to create incident
   - When site comes back up, offer to resolve incident
   - Or: auto-create/resolve based on uptime status

6. **Integration with Maintenance Windows:**
   - When maintenance window starts with "update status page" enabled
   - Create scheduled maintenance incident automatically
   - Resolve when maintenance ends

---

## Implementation Order

1. **Cloudflare Connection Setup** - foundation
2. **Cloudflare DNS Management** - extends DNS viewer
3. **Cloudflare Cache** - simple, useful
4. **Cloudflare Security** - firewall integration
5. **Cloudflare Analytics** - read-only, nice to have
6. **Public Status Page** - standalone, integrates with uptime

## Environment Variables

```
CLOUDFLARE_API_URL=https://api.cloudflare.com/client/v4
```

## Notes

- Cloudflare API token should have minimal required permissions
- Store tokens encrypted
- Rate limit Cloudflare API calls
- Cache Cloudflare responses where appropriate
- Status page should work without JavaScript (accessibility)
- Status page needs to handle high traffic (cache aggressively)
