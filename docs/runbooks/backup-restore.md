# Backup & Restore Runbook

## Quick Reference

| Action | Command |
|--------|---------|
| Check backup queue | `docker compose -f docker-compose.prod.yml exec app php artisan horizon:status` |
| View backup jobs | `docker compose -f docker-compose.prod.yml logs --tail=50 horizon \| grep -i backup` |
| Check failed jobs | `docker compose -f docker-compose.prod.yml exec app php artisan queue:failed` |
| Retry failed backup | `docker compose -f docker-compose.prod.yml exec app php artisan queue:retry <job-id>` |
| Retry all failed | `docker compose -f docker-compose.prod.yml exec app php artisan queue:retry all` |
| Clear failed jobs | `docker compose -f docker-compose.prod.yml exec app php artisan queue:flush` |
| Restart queue workers | `docker compose -f docker-compose.prod.yml exec app php artisan queue:restart` |

## Architecture

- Backups run as queued jobs on the `backups` queue (managed by Horizon)
- `CreateBackup` job: timeout 30min, 2 tries, 2min backoff, unique per site for 30min
- `RestoreBackup` job: timeout 60min, 1 try, 1GB memory limit
- `CreateIncrementalBackup` job: same as CreateBackup but only changed files
- Storage: Dropbox (primary), S3, or local filesystem
- Backup types: `full` (files + database), `database` (DB only), `files` (files only)

## Monitoring Backup Status

### Via UI
1. Go to site detail > Backups tab
2. Status column shows: pending, in_progress, completed, failed
3. Progress bar shows percentage during backup

### Via Database
```sql
-- Recent backups with status
SELECT b.id, s.name, b.type, b.status, b.progress, b.error_message, b.created_at
FROM backups b JOIN sites s ON b.site_id = s.id
ORDER BY b.created_at DESC LIMIT 20;

-- Failed backups in last 24h
SELECT b.id, s.name, b.type, b.error_message, b.created_at
FROM backups b JOIN sites s ON b.site_id = s.id
WHERE b.status = 'failed' AND b.created_at > NOW() - INTERVAL '24 hours'
ORDER BY b.created_at DESC;

-- Active/stuck backups (running for more than 30 min)
SELECT b.id, s.name, b.type, b.progress, b.started_at
FROM backups b JOIN sites s ON b.site_id = s.id
WHERE b.status = 'in_progress' AND b.started_at < NOW() - INTERVAL '30 minutes';
```

### Via Horizon Dashboard
- URL: https://manager.simplead.ro/horizon
- Check the `backups` queue for pending/processing jobs
- Check "Failed Jobs" tab for errors

## Handling Failed Backups

### Step 1: Identify the failure
```bash
docker compose -f docker-compose.prod.yml exec app php artisan queue:failed
```
Look at the `exception` column for the error type.

### Step 2: Common failure reasons

| Error | Cause | Fix |
|-------|-------|-----|
| `Dropbox API error [401]` | Token expired | Reconnect Dropbox in Settings > Storage |
| `Connection timed out` | WP site unreachable | Check site is up, verify API endpoint |
| `HMAC signature mismatch` | API credentials changed | Re-save credentials in site overview |
| `Out of memory` | Backup too large | Check Horizon memory limit, increase if needed |
| `Disk space` | Server full | Clear old backups, check retention policy |
| `Circuit breaker open` | Too many failures | Reset circuit breaker from site overview |

### Step 3: Retry or fix

```bash
# Retry a specific failed job
docker compose -f docker-compose.prod.yml exec app php artisan queue:retry <job-id>

# If the issue is fixed, retry all
docker compose -f docker-compose.prod.yml exec app php artisan queue:retry all
```

### Step 4: Manual backup trigger
If automated retry doesn't work, trigger manually from the UI:
1. Go to site detail > Backups
2. Click "Database Backup" or "Full Backup"

## Handling Stuck Backups

A backup is "stuck" if it's been `in_progress` for more than 30 minutes.

### Release a stuck backup lock
```bash
# Find the stuck backup
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \$stuck = \App\Models\Backup::where('status', 'in_progress')
        ->where('started_at', '<', now()->subMinutes(30))
        ->get();
    foreach (\$stuck as \$b) {
        echo \"Backup #{$b->id} for site #{$b->site_id} stuck since {$b->started_at}\n\";
        \$b->update(['status' => 'failed', 'error_message' => 'Manually marked as failed (stuck)', 'completed_at' => now()]);
    }
"
```

### Clear unique job lock
`CreateBackup` uses `ShouldBeUnique` with a 30-min lock. If a backup was killed mid-execution, the lock may persist:
```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \$site = \App\Models\Site::find(SITE_ID);
    \Illuminate\Support\Facades\Cache::forget('laravel_unique_job:App\Jobs\CreateBackup'.\$site->id);
    echo 'Lock cleared for site '.\$site->name;
"
```

## Restore Procedure

### Via UI (recommended)
1. Go to site detail > Backups
2. Find the backup to restore
3. Click "Restore" button
4. Confirm in the dialog (choose DB only, files only, or both)
5. Monitor progress in the UI

### Via Artisan (emergency)
```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \$backup = \App\Models\Backup::find(BACKUP_ID);
    \App\Jobs\RestoreBackup::dispatch(\$backup, restoreDatabase: true, restoreFiles: true);
    echo 'Restore dispatched for backup #'.\$backup->id;
"
```

### Restore safety
- Restore creates a pre-restore backup automatically (if configured)
- Restore runs on the `backups` queue with 60min timeout
- Only one restore can run per site at a time
- Files are uploaded back to the WP site via the connector plugin API

## Retention Policy

Retention is managed by `RetentionService` and `RetentionCleanup` scheduled job:
- Runs daily via the scheduler
- Respects `retention_type` and `retention_value` from `BackupConfig`
- Locked backups are never deleted by retention
- Chain-aware: incremental backups are only deleted when their full parent is deleted

### Check retention settings
```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \App\Models\BackupConfig::with('site')->get()->each(function (\$c) {
        echo \"{$c->site->name}: {$c->retention_type} = {$c->retention_value}\n\";
    });
"
```

## Dropbox Storage

### Re-authorize Dropbox
If Dropbox token expires or is revoked:
1. Go to Settings > Storage Destinations
2. Click "Reconnect" on the Dropbox destination
3. Complete OAuth flow
4. Test connection

### Check Dropbox usage
```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \$dest = \App\Models\StorageDestination::where('type', 'dropbox')->first();
    echo 'Used: '.number_format(\$dest->used_bytes / 1073741824, 2).' GB';
    echo ' / Quota: '.number_format(\$dest->quota_bytes / 1073741824, 2).' GB';
"
```
