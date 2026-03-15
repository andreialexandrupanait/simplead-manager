# WordPress Full Backup — Analysis & Implementation Brief
**SimpleAD Manager | SimpleAD.ro**
**Status:** Research & Decision Document
**Goal:** Replace current backup solution that fails on large sites and is too slow

---

## 1. Problem Statement

Current backup implementation has two critical failures:
- **Timeout failures** on large sites (typically > 500MB total or > 100MB DB)
- **Excessive duration** — full backups block resources and take too long per site

Root causes (common across naive implementations):
- PHP execution time limits (`max_execution_time`)
- PHP memory limits (`memory_limit`)
- Single-process, non-chunked file archiving
- No streaming to remote storage — full archive built locally first, then uploaded
- No parallelism between DB dump and file archiving

---

## 2. Backup Architecture Options

### Option A — WP-CLI via Agent (Recommended Primary)

**How it works:**  
The SimpleAD agent (already deployed on each WordPress site) executes `wp-cli` commands directly on the server. WP-CLI runs as a CLI process — completely bypasses PHP web timeout limits.

**Commands used:**
```bash
# Database export
wp db export /tmp/backup-db-{timestamp}.sql --allow-root

# Files archive (excluding cache, logs, etc.)
tar -czf /tmp/backup-files-{timestamp}.tar.gz /var/www/html \
  --exclude='*/cache/*' \
  --exclude='*/uploads/cache/*' \
  --exclude='*/.git/*' \
  --exclude='*/node_modules/*'

# Stream directly to S3 (no local storage needed)
wp db export - --allow-root | gzip | aws s3 cp - s3://bucket/site/db-{timestamp}.sql.gz

# Or pipe tar directly to S3
tar -czf - /var/www/html | aws s3 cp - s3://bucket/site/files-{timestamp}.tar.gz
```

**Advantages:**
- No PHP limits apply (CLI process)
- Streaming to S3 eliminates local disk bottleneck
- Native WordPress DB export (handles encoding, foreign keys correctly)
- WP-CLI already common on managed WordPress servers
- Works with the existing pull-based agent command queue

**Disadvantages:**
- WP-CLI must be installed on the server (not guaranteed on all hosts)
- Agent must have write access to `/tmp` or a writable scratch dir
- AWS CLI must be available OR agent streams manually

**Verdict: Best approach for servers where WP-CLI is available (>80% of cases)**

---

### Option B — Chunked PHP REST API Backup (Fallback for shared hosting)

**How it works:**  
A lightweight PHP agent plugin exposes a private REST endpoint that backs up the site in **chunks** — a fixed number of files or MB per request. The Laravel backend orchestrates multiple calls via the job queue.

**Flow:**
```
Laravel Job → POST /wp-json/simplead/v1/backup/start
           → POST /wp-json/simplead/v1/backup/chunk?session=xxx&offset=0
           → POST /wp-json/simplead/v1/backup/chunk?session=xxx&offset=1
           → ... (loop until done)
           → POST /wp-json/simplead/v1/backup/finalize?session=xxx
```

Each chunk runs in 20–30 seconds max, then the next job fires. No single PHP request exceeds limits.

**Advantages:**
- Works on shared hosting (no SSH, no WP-CLI required)
- No server-level dependencies
- Fully controllable from Laravel job queue

**Disadvantages:**
- Requires a custom PHP plugin installed and kept updated on every site
- More complex orchestration (session management, resume logic)
- Slower than WP-CLI (HTTP overhead per chunk)
- Authentication/security of the REST endpoint must be hardened

**Verdict: Good fallback for low-end shared hosting clients**

---

### Option C — Direct mysqldump + rsync via SSH

**How it works:**  
Agent opens an SSH tunnel (or uses stored credentials) and runs `mysqldump` + `rsync`/`tar` directly.

```bash
# DB dump
mysqldump -u {user} -p{pass} {dbname} | gzip > backup-db.sql.gz

# Files sync (incremental-friendly)
rsync -avz --exclude='cache' /var/www/html/ /backup/site/files/
```

**Advantages:**
- Fastest possible method
- Supports incremental file sync (rsync)
- No WordPress dependency

**Disadvantages:**
- Requires SSH access + stored DB credentials
- Many managed WP hosts do NOT allow SSH
- Security risk: storing SSH keys + DB passwords per site
- Not feasible for cPanel/shared hosting clients

**Verdict: Powerful but too restrictive — only viable for VPS/dedicated clients**

---

### Option D — UpdraftPlus / All-in-One WP Migration API

**How it works:**  
Paid versions of UpdraftPlus, BackWPup, or Duplicator Pro expose REST API or WP-CLI integrations that can be triggered programmatically.

- **UpdraftPlus Migrator (paid):** REST API trigger, cloud destination support
- **Duplicator Pro:** CLI trigger, large site support built-in
- **All-in-One WP Migration (paid):** API add-on

**Advantages:**
- Battle-tested, handles edge cases (serialized data, large DBs)
- Large site support already built in
- Handles multisite

**Disadvantages:**
- **Per-site licensing costs** — expensive at scale (100+ sites)
- Dependency on third-party plugin being installed, activated, and licensed on every site
- Version drift / update issues across fleet
- Adds plugin weight to every managed site
- License management becomes a product problem

**Verdict: Not suitable for a managed SaaS at scale. Reject.**

---

### Option E — Incremental Backup (Advanced, Phase 2)

**How it works:**  
First backup is a full backup. Subsequent backups only capture changed files (via file modification timestamps or checksums) and a DB binary log or full dump.

**Technology:**
- Files: `rsync --checksum`, or maintain a file manifest (path + mtime + size)
- DB: Full dump (WordPress DB is rarely >500MB; incremental DB is complex)
- Storage: S3 versioning or custom manifest in `backups` table

**Advantages:**
- Dramatically reduces backup duration for large sites after first run
- Reduces S3 storage costs
- Enables faster RPO (backup every 1h instead of every 24h)

**Disadvantages:**
- Significantly more complex implementation
- Restore logic must reconstruct full state from base + deltas
- Requires manifest tracking in DB per site

**Verdict: Phase 2 roadmap item. Not for immediate implementation.**

---

## 3. Comparison Matrix

| Criterion | WP-CLI (A) | Chunked PHP (B) | SSH Direct (C) | Plugin API (D) | Incremental (E) |
|---|---|---|---|---|---|
| **Large site support** | ✅ Excellent | ✅ Good | ✅ Excellent | ✅ Good | ✅ Best |
| **Speed** | ✅ Fast | ⚠️ Medium | ✅ Fastest | ⚠️ Medium | ✅ Fast |
| **Shared hosting compat.** | ⚠️ WP-CLI req. | ✅ Yes | ❌ No | ✅ Yes | ⚠️ Depends |
| **No extra plugins** | ✅ Yes | ❌ Plugin needed | ✅ Yes | ❌ Plugin needed | ✅ Yes |
| **Implementation complexity** | ⚠️ Medium | ⚠️ Medium | ✅ Low | ✅ Low | ❌ High |
| **Operating cost at scale** | ✅ Low | ✅ Low | ✅ Low | ❌ High ($$$) | ✅ Low |
| **Fits agent architecture** | ✅ Perfect | ✅ Good | ⚠️ Partial | ⚠️ Partial | ✅ Good |
| **Streaming to S3** | ✅ Yes | ⚠️ Possible | ✅ Yes | ❌ No | ✅ Yes |

---

## 4. Recommended Architecture: Hybrid A + B

### Strategy

```
Agent detects WP-CLI available?
  YES → Use WP-CLI path (Option A)
  NO  → Fall back to Chunked PHP REST path (Option B)
```

This covers:
- **~80–85% of sites** → WP-CLI path (fast, reliable, no limits)
- **~15–20% of sites** → Chunked PHP path (shared hosting, restrictive environments)

---

## 5. WP-CLI Backup Flow (Primary Path)

### 5.1 Agent-Side Execution

The agent receives a `run_backup` command from the SimpleAD command queue and executes:

**Phase 1 — Database:**
```bash
wp db export - --allow-root --path=/var/www/html \
  | gzip \
  | aws s3 cp - s3://{bucket}/{site_id}/backups/{timestamp}/db.sql.gz \
    --storage-class STANDARD_IA
```

**Phase 2 — Files (streamed, no local temp):**
```bash
tar -czf - \
  --exclude='*/cache/*' \
  --exclude='*/wp-content/cache/*' \
  --exclude='*/wp-content/uploads/cache/*' \
  --exclude='*/.git' \
  --exclude='*/node_modules' \
  --exclude='*/backup*' \
  /var/www/html \
  | aws s3 cp - s3://{bucket}/{site_id}/backups/{timestamp}/files.tar.gz \
    --storage-class STANDARD_IA
```

**Phase 3 — Manifest:**
```bash
wp core version --allow-root > version.txt
wp plugin list --format=json --allow-root > plugins.json
# Reported back to SimpleAD via agent check-in
```

### 5.2 Agent Reports Back

After completion, agent posts to the SimpleAD API:
```json
{
  "command_id": "...",
  "status": "completed",
  "result": {
    "db_size_bytes": 45234567,
    "files_size_bytes": 234567890,
    "duration_seconds": 147,
    "s3_db_key": "site_id/backups/2025-01-15T10:00:00/db.sql.gz",
    "s3_files_key": "site_id/backups/2025-01-15T10:00:00/files.tar.gz",
    "wp_version": "6.7.1",
    "php_version": "8.2.0"
  }
}
```

### 5.3 Laravel Side

```
BackupSite Job
  → Dispatch command to agent queue (existing mechanism)
  → Poll for completion (existing polling mechanism)
  → On success: record to `site_backups` table
  → On failure: retry logic + alert notification
  → Prune old backups per retention policy
```

---

## 6. Chunked PHP Backup Flow (Fallback Path)

### 6.1 Custom Lightweight Plugin

A minimal plugin (`simplead-agent-backup.php`) installed via the existing agent deploy mechanism:

**Endpoints:**
- `POST /wp-json/simplead-backup/v1/start` — Initialize session, returns `session_id`
- `GET /wp-json/simplead-backup/v1/db` — Export DB, stream directly to pre-signed S3 URL
- `GET /wp-json/simplead-backup/v1/files/manifest` — Returns list of files with sizes
- `POST /wp-json/simplead-backup/v1/files/chunk` — Archives and uploads one chunk (N files) to pre-signed S3 URL
- `POST /wp-json/simplead-backup/v1/finalize` — Merges chunks, marks complete

**Authentication:** HMAC-signed request with shared secret per site (already in agent model).

**Chunk size:** ~50MB or 1000 files per chunk (configurable), targeting < 25s execution per chunk.

### 6.2 Laravel Orchestration

```
BackupSiteChunked Job
  → POST /start → get session_id
  → GET /db → triggers DB export to S3
  → GET /files/manifest → get total file list
  → Loop: POST /files/chunk with offset until complete
  → POST /finalize
  → Record backup to DB
```

Each chunk dispatch is a separate queued job with 30s delay between retries if a chunk fails.

---

## 7. Database Schema Changes

### New Table: `site_backups`

```sql
CREATE TABLE site_backups (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- pending | running | completed | failed | pruned
    
    backup_type VARCHAR(20) NOT NULL DEFAULT 'full',
    -- full | db_only | files_only
    
    method VARCHAR(20) NOT NULL DEFAULT 'wpcli',
    -- wpcli | chunked_php | manual
    
    storage_destination_id BIGINT REFERENCES storage_destinations(id),
    
    -- S3 / storage paths
    db_path VARCHAR(500),
    files_path VARCHAR(500),
    manifest_path VARCHAR(500),
    
    -- Metrics
    db_size_bytes BIGINT,
    files_size_bytes BIGINT,
    total_size_bytes BIGINT GENERATED ALWAYS AS (COALESCE(db_size_bytes,0) + COALESCE(files_size_bytes,0)) STORED,
    duration_seconds INT,
    
    -- Site snapshot at backup time
    wp_version VARCHAR(20),
    php_version VARCHAR(20),
    plugins_snapshot JSONB,
    
    -- Chunk tracking (for chunked_php method)
    total_chunks INT,
    completed_chunks INT DEFAULT 0,
    
    -- Error info
    error_message TEXT,
    retry_count INT DEFAULT 0,
    
    -- Timestamps
    scheduled_at TIMESTAMPTZ,
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ, -- based on retention policy
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_site_backups_site_id ON site_backups(site_id);
CREATE INDEX idx_site_backups_status ON site_backups(status);
CREATE INDEX idx_site_backups_created_at ON site_backups(created_at DESC);
```

### Modified Table: `sites` (additions)

```sql
ALTER TABLE sites ADD COLUMN IF NOT EXISTS backup_method VARCHAR(20) DEFAULT 'auto';
-- auto | wpcli | chunked_php | disabled

ALTER TABLE sites ADD COLUMN IF NOT EXISTS backup_schedule VARCHAR(20) DEFAULT 'daily';
-- daily | weekly | manual

ALTER TABLE sites ADD COLUMN IF NOT EXISTS backup_retention_days INT DEFAULT 30;

ALTER TABLE sites ADD COLUMN IF NOT EXISTS last_backup_at TIMESTAMPTZ;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS last_backup_status VARCHAR(20);
ALTER TABLE sites ADD COLUMN IF NOT EXISTS last_backup_size_bytes BIGINT;
```

---

## 8. Key Exclusions List (Standard for All Backups)

Files and directories to always exclude:

```
# Cache
wp-content/cache/
wp-content/w3tc-config/
wp-content/litespeed/
wp-content/et-cache/

# Backup plugin directories (recursive backup prevention)
wp-content/updraft/
wp-content/backup*/
wp-content/ai1wm-backups/
wp-content/managewp/

# Logs
wp-content/debug.log
*.log

# Dev artifacts
node_modules/
.git/
.svn/

# OS artifacts
.DS_Store
Thumbs.db
```

---

## 9. Performance Targets

| Site Size | Method | Target Duration |
|---|---|---|
| < 100MB | WP-CLI | < 60s |
| 100MB – 500MB | WP-CLI | < 5 min |
| 500MB – 2GB | WP-CLI (streaming) | < 20 min |
| > 2GB | WP-CLI (streaming) | < 45 min |
| Any size, shared hosting | Chunked PHP | < 2h (async) |

---

## 10. Restore Flow (Must be Defined Alongside Backup)

Backup without restore is incomplete. SimpleAD must support:

1. **Download backup** — Pre-signed S3 URL, expires in 1h, delivered via UI
2. **One-click restore (Phase 2)** — Agent receives restore command, pulls from S3, extracts
3. **Partial restore** — DB only or files only restore option

---

## 11. Implementation Phases

### Phase 1 — Core WP-CLI Backup (2 weeks)
- [ ] `site_backups` table + migration
- [ ] `SiteBackup` Eloquent model
- [ ] `BackupService` — WP-CLI command builder
- [ ] `RunSiteBackupJob` — dispatches agent command, polls, records result
- [ ] Backup scheduler (daily/weekly cron per site)
- [ ] Backup listing UI in site detail view
- [ ] S3 stream upload integration (reuse existing `StorageDestination`)
- [ ] Retention pruning job

### Phase 2 — Chunked PHP Fallback (2 weeks)
- [ ] `simplead-backup` PHP plugin (lightweight, versioned)
- [ ] `BackupChunkOrchestratorJob` — session management + chunk loop
- [ ] Auto-detection logic: if WP-CLI unavailable → switch to chunked
- [ ] Plugin deploy mechanism via agent

### Phase 3 — UI & Reporting (1 week)
- [ ] Backup dashboard (global, per site)
- [ ] Backup size trends chart (ApexCharts)
- [ ] Restore download button (pre-signed URL)
- [ ] Backup health indicator in site list
- [ ] Alert notifications for failed backups

### Phase 4 — Incremental Backups (Future)
- [ ] File manifest system
- [ ] Delta detection (mtime + size comparison)
- [ ] Incremental tar (files changed since last backup)
- [ ] Manifest storage in `backup_manifests` table

---

## 12. Open Decisions

| # | Question | Options | Recommended |
|---|---|---|---|
| 1 | Where does the agent run WP-CLI? | In WordPress root OR via full path | Full path detection at agent startup |
| 2 | Chunk storage during PHP backup | All direct-to-S3 OR temp server storage | Direct-to-S3 via pre-signed URL |
| 3 | Backup method auto-detection trigger | Agent probes WP-CLI on install | Yes — store capability flag in `sites.backup_method` |
| 4 | Multiple storage destinations per backup | Single destination per backup | Single (inherit site's default destination) |
| 5 | DB + Files parallel or sequential? | Parallel (2 processes) or sequential | Sequential for simplicity (Phase 1), parallel in Phase 2 |

---

## 13. Out of Scope (Explicitly)

- UpdraftPlus / third-party plugin integrations
- Multisite network backup (future consideration)
- Real-time continuous backup / WAL streaming
- Git-based backup
- Staging site creation from backup (separate feature)

---

*Document prepared for Claude Code implementation. All code targets Laravel 11, Livewire 3, PostgreSQL 16, existing agent pull architecture, and S3/Dropbox via `storage_destinations` table.*
