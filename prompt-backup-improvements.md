## Part 1: Real-Time Restore Progress

The restore process has the same problem as backup did — no progress feedback. Apply the same pattern used for backup progress.

### Add columns to `backups` table (if not already present from backup progress):

```php
$table->string('restore_status')->nullable(); // pending, in_progress, completed, failed
$table->string('restore_stage')->nullable(); // downloading, verifying, restoring_db, restoring_files, syncing, done
$table->integer('restore_progress_percent')->default(0);
$table->string('restore_progress_message')->nullable();
```

### Update RestoreBackup job to report progress at each step:

```
Step 1: "Downloading backup from Dropbox..."           → 10%
Step 2: "Download complete (245 MB)"                   → 25%
Step 3: "Verifying checksum..."                        → 30%
Step 4: "Checksum verified ✓"                          → 35%
Step 5: "Extracting archive..."                        → 40%
Step 6: "Restoring database..."                        → 55%
Step 7: "Database restored successfully"               → 70%
Step 8: "Restoring files..."                           → 80%  (skip if database-only)
Step 9: "Files restored successfully"                  → 90%
Step 10: "Syncing site data..."                        → 95%
Step 11: "Restore complete!"                           → 100%
```

### UI — Same polling pattern:

When a restore is in_progress, show a progress card on the backups page:

```
┌─────────────────────────────────────────────────────────────────────┐
│  🔄 Restoring from backup — Feb 2, 2026 at 03:00                  │
│                                                                     │
│  ████████████████████████░░░░░░░░░░░  70%                         │
│                                                                     │
│  Database restored successfully. Restoring files...                │
│                                                                     │
│  Started 1 minute ago                                               │
└─────────────────────────────────────────────────────────────────────┘
```

Use `wire:poll.3s` while restore is active. Green when done, red if failed. Auto-dismiss after 5 seconds and refresh the page data.

---

## Part 2: Additional Backup Features

Implement all of the following improvements:

### 1. Backup Size Estimation
Before starting a backup, show the estimated size based on the site's known DB size and uploads size:
- "Estimated backup size: ~245 MB (DB: 45 MB + Files: 200 MB)"
- Show this on the backup confirmation or right next to the backup buttons
- Use the `db_size_mb` and `uploads_size_mb` already cached on the site model

### 2. Backup Comparison
On the backup history table, show what changed between backups:
- Size difference from previous backup: "+2.3 MB" or "-1.1 MB" with color (green for smaller, yellow for bigger)
- Display this as a small badge next to the file size column

### 3. Download Backup to Browser
Add a "Download" button on each backup row that generates a temporary signed URL:
- For Dropbox: use Dropbox's `get_temporary_link` API to get a direct download URL
- For S3: generate a pre-signed URL (valid for 1 hour)
- For Local: serve through Laravel with a signed route
- The download button should open in a new tab / trigger browser download
- Implementation: create a route `GET /backups/{backup}/download` that generates the appropriate link and redirects

### 4. Backup Notes
Allow the user to add/edit a note on any backup:
- Click on the backup row or a small "note" icon to add a note
- "Pre-update backup before WooCommerce 8.6" or "Client requested rollback point"
- The notes field already exists in the backups table, just wire it to the UI
- Inline edit with Alpine.js — click to edit, blur/enter to save

### 5. Auto-Backup Before Restore
When the user initiates a restore, automatically create a backup of the current state BEFORE restoring:
- Show in the restore confirmation modal: "A backup of the current state will be created automatically before restoring"
- Tag this auto-backup with trigger: 'pre_restore' and lock it
- Wait for the pre-restore backup to complete before starting the actual restore
- If the pre-restore backup fails, warn the user but let them proceed if they confirm

### 6. Backup Health Indicator on Site Card
Show backup status on the main site card:
- Green checkmark + "2h ago" if last backup succeeded within the scheduled interval
- Yellow warning + "3 days ago" if backup is overdue (missed more than 1 scheduled interval)
- Red X + "Failed" if last backup failed
- Gray dash if no backup configured

### 7. Storage Quota Warning
When storage usage approaches the quota (for Dropbox free tier especially):
- Show a warning bar on the backups page: "⚠️ Dropbox storage is 85% full (1.7 GB / 2 GB)"
- Change color: yellow at 75%, red at 90%
- Show in the storage destinations settings too

### 8. Bulk Backup
Add a "Backup All Sites" button on the global dashboard or a global backups page:
- Queues backups for all sites with configured backup schedules
- Shows progress: "Backing up 3 of 12 sites..."
- Create a simple global backups overview page at `/backups` (accessible from sidebar) that shows recent backups across all sites

Work autonomously. Implement all features listed above.
