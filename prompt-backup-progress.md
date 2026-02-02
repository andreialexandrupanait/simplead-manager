The backup process currently shows only a text message that it started, then nothing until it finishes (or fails silently). This is a terrible experience — backups can take 5-30 minutes and the user has no idea what's happening.

Build a real-time backup progress system using Laravel's built-in broadcasting + Livewire polling (or Reverb/Pusher if already configured, otherwise use simple Livewire polling as fallback).

## How it should work:

### 1. Add a `progress` column to the `backups` table:

```php
$table->string('stage')->nullable(); // downloading_db, downloading_files, compressing, uploading, cleanup, done
$table->integer('progress_percent')->default(0); // 0-100
$table->string('progress_message')->nullable(); // human-readable status
```

### 2. Update the CreateBackup job to report progress at each step:

```
Step 1: "Requesting database backup from WordPress..."     → stage: downloading_db, progress: 10%
Step 2: "Database downloaded (12 MB)"                      → stage: downloading_db, progress: 25%
Step 3: "Requesting files backup from WordPress..."        → stage: downloading_files, progress: 30%
Step 4: "Files downloaded (230 MB)"                        → stage: downloading_files, progress: 55%
Step 5: "Creating archive..."                              → stage: compressing, progress: 65%
Step 6: "Archive created (245 MB)"                         → stage: compressing, progress: 75%
Step 7: "Uploading to Dropbox..."                          → stage: uploading, progress: 80%
Step 8: "Upload complete"                                  → stage: uploading, progress: 95%
Step 9: "Cleaning up temporary files..."                   → stage: cleanup, progress: 98%
Step 10: "Backup complete!"                                → stage: done, progress: 100%
```

At each step, update the backup record in the database:
```php
$backup->update([
    'stage' => 'downloading_db',
    'progress_percent' => 10,
    'progress_message' => 'Requesting database backup from WordPress...',
]);
```

### 3. UI — Show a progress card while backup is running:

When a backup is in_progress, show this instead of (or above) the backup history table:

```
┌─────────────────────────────────────────────────────────────────────┐
│  🔄 Backup in Progress                                             │
│                                                                     │
│  ████████████████████░░░░░░░░░░░░░░░  55%                         │
│                                                                     │
│  Downloading files from WordPress... (230 MB)                      │
│                                                                     │
│  Started 2 minutes ago                                              │
└─────────────────────────────────────────────────────────────────────┘
```

The progress bar should:
- Be animated (smooth transition between percentages)
- Change color: blue while in progress, green when done, red if failed
- Show the current stage message below the bar
- Show elapsed time ("Started X minutes ago")
- Auto-update every 3 seconds using `wire:poll.3s` on the progress card component (ONLY poll while a backup is in_progress, stop polling when done or failed)

### 4. Livewire polling approach (simplest, no extra infrastructure):

```blade
{{-- Only renders and polls while backup is active --}}
@if($activeBackup && $activeBackup->status === 'in_progress')
    <div wire:poll.3s="refreshProgress">
        {{-- Progress card here --}}
    </div>
@endif
```

```php
// In Livewire component
public function refreshProgress()
{
    $this->activeBackup = Backup::where('site_id', $this->site->id)
        ->where('status', 'in_progress')
        ->latest()
        ->first();
}
```

### 5. When backup completes or fails:

- Progress bar fills to 100% green + "✅ Backup complete! 245 MB saved to Dropbox" 
- OR turns red + "❌ Backup failed: Connection timeout" with the error message
- After 5 seconds, the progress card auto-dismisses and the backup appears in the history table
- Refresh the backup history table automatically

### 6. For database-only backups (faster, fewer steps):

```
Step 1: "Requesting database backup..."    → 15%
Step 2: "Database downloaded (12 MB)"      → 45%
Step 3: "Creating archive..."             → 65%
Step 4: "Uploading to Dropbox..."         → 80%
Step 5: "Backup complete!"               → 100%
```

Work autonomously. Fix the CreateBackup job to update progress at each stage, add the migration, and build the polling progress UI.
