# Phase 1: Foundation Features

Build these 3 features in order. Reference existing project architecture and conventions.

---

## Feature 1: Slack / Telegram Notifications

**What it does:**
Users can add Slack webhooks or Telegram bots to receive instant alerts when events happen (site down, SSL expiring, backup failed, etc.).

**Requirements:**

1. **Database:**
   - `notification_channels` - stores user's Slack/Telegram configurations
   - `notification_logs` - history of sent notifications

2. **Notification Channel has:**
   - Type (slack or telegram)
   - Name (friendly label)
   - Webhook URL (for Slack)
   - Bot token + Chat ID (for Telegram, token encrypted)
   - List of events to notify (JSON array)
   - Active/verified status
   - Last used timestamp
   - Last error message

3. **Services needed:**
   - `SlackNotificationService` - sends formatted messages via webhook
   - `TelegramNotificationService` - sends via Telegram Bot API
   - `MultiChannelNotificationService` - orchestrates sending to all user's active channels

4. **Job:**
   - `SendChannelNotificationJob` - queued job that sends to one channel, logs result

5. **Events to support:**
   - site_down, site_up
   - ssl_expiring, ssl_expired
   - domain_expiring
   - backup_completed, backup_failed
   - update_available
   - security_issue
   - performance_drop
   - maintenance_started, maintenance_ended

6. **UI (Livewire):**
   - Settings page: `/settings/notifications`
   - List existing channels with status
   - Add channel modal (type selector, credentials, event checkboxes)
   - Edit channel
   - Send test button
   - Toggle active/pause
   - Delete channel

7. **Integration:**
   - Create helper method to notify from anywhere: `notifySiteEvent($site, $event, $data)`
   - This will be called by existing jobs (uptime, SSL, backup, etc.)

---

## Feature 2: Maintenance Windows

**What it does:**
Schedule maintenance periods where monitoring alerts are paused. No more false alerts during planned work.

**Requirements:**

1. **Database:**
   - `maintenance_windows` table

2. **Maintenance Window has:**
   - Site ID
   - User ID (who created)
   - Title and description
   - Start time and end time
   - Status: scheduled, active, completed, cancelled
   - Which monitors to pause (uptime, ssl, performance, backups, links - all booleans)
   - Notification settings (notify on start, notify on end)
   - Actual start/end timestamps

3. **Service:**
   - `MaintenanceService`
   - Check if site is in maintenance for specific monitor type
   - Process scheduled windows (start when time comes)
   - Process ending windows (complete when time passes)
   - Methods: startMaintenance(), endMaintenance(), cancelMaintenance()

4. **Scheduler:**
   - Every minute: check for windows to start/end

5. **Integration with existing jobs:**
   - All monitoring jobs (uptime, SSL, performance, links) must check if site is in maintenance before running
   - If in maintenance, skip silently

6. **UI (Livewire):**
   - Site page tab or section: "Maintenance"
   - Show active maintenance (if any) with "End Now" button
   - List upcoming scheduled windows
   - List past windows
   - Create window modal (title, description, start/end datetime pickers, checkboxes for what to pause)
   - Edit scheduled window
   - Cancel window
   - Start now button (for scheduled windows)

7. **Notifications:**
   - Send notification when maintenance starts (if enabled)
   - Send notification when maintenance ends (if enabled)
   - Use the Slack/Telegram system from Feature 1

---

## Feature 3: DNS Records Viewer

**What it does:**
View all DNS records for a site's domain. Useful for debugging and preparation for Cloudflare integration.

**Requirements:**

1. **Database:**
   - `dns_records_cache` - cached DNS lookup results

2. **Cached data includes:**
   - Domain name
   - A records (IPv4)
   - AAAA records (IPv6)
   - CNAME records
   - MX records (with priority, sorted)
   - TXT records
   - NS records (nameservers)
   - SOA record
   - Analysis flags: has_www, uses_cloudflare, has_spf, has_dmarc, has_dkim
   - Detected mail provider (Google, Microsoft, etc.)
   - Check timestamp

3. **Service:**
   - `DnsService`
   - Fetch all record types using PHP's dns_get_record()
   - Analyze records (detect Cloudflare, SPF, DMARC, DKIM, mail provider)
   - Cache results

4. **UI (Livewire):**
   - Site page tab or section: "DNS"
   - Show domain being checked
   - Quick stats cards: Total records, Uses Cloudflare (yes/no), Email security score
   - Table of all records grouped by type (A, AAAA, CNAME, MX, TXT, NS)
   - For MX: show priority and host
   - For TXT: show full record (SPF, DMARC highlighted)
   - "Refresh" button to re-fetch
   - Last checked timestamp

5. **Email security indicators:**
   - SPF: ✓ or ✗
   - DMARC: ✓ or ✗
   - DKIM: ✓ or ✗
   - Show simple score (0-100%) based on these 3

6. **Detection logic:**
   - Cloudflare: NS records contain "cloudflare.com"
   - SPF: TXT record starts with "v=spf1"
   - DMARC: TXT record at _dmarc.domain contains "v=DMARC1"
   - DKIM: Check common selectors (default, google, selector1, selector2, k1, mail) at selector._domainkey.domain
   - Mail provider: Match MX records against known providers (Google, Microsoft, Zoho, etc.)

---

## Implementation Order

1. **Slack/Telegram first** - foundation for all notifications
2. **Maintenance Windows second** - depends on notifications
3. **DNS Viewer third** - standalone, prepares for Cloudflare

## Notes

- Use existing UI components and styling (WPMUDEV-inspired, purple accent)
- All Livewire components follow existing patterns
- Encrypt sensitive data (Telegram bot tokens)
- Queue notification sending (don't block)
- Add to existing Site model relationships where needed
