<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupStatus;
use App\Helpers\FormatHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $storage_destination_id
 * @property string $type
 * @property string $trigger
 * @property \App\Enums\BackupStatus $status
 * @property string|null $stage
 * @property int $progress_percent
 * @property string|null $progress_message
 * @property string|null $error_message
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property string|null $checksum
 * @property string|null $upload_method
 * @property bool $includes_files
 * @property bool $includes_database
 * @property string|null $wp_version
 * @property string|null $php_version
 * @property int|null $plugins_count
 * @property int|null $themes_count
 * @property float|null $db_size_mb
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $duration_seconds
 * @property bool $is_locked
 * @property bool $is_encrypted
 * @property string|null $lock_reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_restored_at
 * @property string|null $notes
 * @property \App\Enums\BackupStatus $restore_status
 * @property string|null $restore_stage
 * @property int $restore_progress_percent
 * @property string|null $restore_progress_message
 * @property string|null $restore_error_message
 * @property int|null $parent_backup_id
 * @property string|null $manifest_path
 * @property int|null $files_changed_count
 * @property int|null $files_deleted_count
 * @property int|null $files_total_count
 * @property string|null $preparation_method
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\StorageDestination|null $storageDestination
 * @property-read \App\Models\Backup|null $parentBackup
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Backup> $incrementals
 */
class Backup extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'storage_destination_id',
        'type',
        'trigger',
        'status',
        'stage',
        'progress_percent',
        'progress_message',
        'error_message',
        'file_path',
        'file_name',
        'file_size',
        'checksum',
        'includes_files',
        'includes_database',
        'wp_version',
        'php_version',
        'plugins_count',
        'themes_count',
        'db_size_mb',
        'started_at',
        'completed_at',
        'duration_seconds',
        'is_locked',
        'is_encrypted',
        'lock_reason',
        'expires_at',
        'last_restored_at',
        'upload_method',
        'preparation_method',
        'notes',
        'restore_status',
        'restore_stage',
        'restore_progress_percent',
        'restore_progress_message',
        'restore_error_message',
        'parent_backup_id',
        'manifest_path',
        'files_changed_count',
        'files_deleted_count',
        'files_total_count',
    ];

    protected $casts = [
        'status' => BackupStatus::class,
        'restore_status' => BackupStatus::class,
        'progress_percent' => 'integer',
        'includes_files' => 'boolean',
        'includes_database' => 'boolean',
        'is_locked' => 'boolean',
        'is_encrypted' => 'boolean',
        'file_size' => 'integer',
        'plugins_count' => 'integer',
        'themes_count' => 'integer',
        'duration_seconds' => 'integer',
        'db_size_mb' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_restored_at' => 'datetime',
        'restore_progress_percent' => 'integer',
        'files_changed_count' => 'integer',
        'files_deleted_count' => 'integer',
        'files_total_count' => 'integer',
    ];

    // Query Scopes

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Failed);
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    // Relationships

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    public function parentBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'parent_backup_id');
    }

    public function incrementals(): HasMany
    {
        return $this->hasMany(Backup::class, 'parent_backup_id');
    }

    public function isIncremental(): bool
    {
        return $this->parent_backup_id !== null;
    }

    public function getChainLengthAttribute(): int
    {
        if ($this->isIncremental()) {
            return 0; // Only meaningful on the root full backup
        }

        return $this->incrementals()->count() + 1;
    }

    public function getFileSizeFormattedAttribute(): string
    {
        if (! $this->file_size) {
            return '—';
        }

        return FormatHelper::bytes($this->file_size);
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    public function getRestoreStatusColorAttribute(): string
    {
        return $this->restore_status->color();
    }

    public function getIsRestoringAttribute(): bool
    {
        return in_array($this->restore_status, [BackupStatus::Pending, BackupStatus::InProgress]);
    }

    public function getSizeDiffAttribute(): ?int
    {
        if ($this->status !== BackupStatus::Completed || ! $this->file_size) {
            return null;
        }

        // Use a cached property to avoid N+1 queries in lists
        if (! isset($this->attributes['_previous_file_size'])) {
            $this->attributes['_previous_file_size'] = static::where('site_id', $this->site_id)
                ->where('status', 'completed')
                ->where('id', '<', $this->id)
                ->whereNotNull('file_size')
                ->orderByDesc('id')
                ->value('file_size') ?? '_null';
        }

        $previous = $this->attributes['_previous_file_size'];
        if ($previous === '_null') {
            return null;
        }

        return $this->file_size - $previous;
    }

    public function getSizeDiffFormattedAttribute(): ?string
    {
        $diff = $this->size_diff;
        if ($diff === null) {
            return null;
        }

        $abs = abs($diff);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        if ($abs === 0) {
            return '0 B';
        }

        $i = (int) floor(log($abs, 1024));
        $formatted = round($abs / pow(1024, $i), 1).' '.$units[$i];

        return $diff >= 0 ? '+'.$formatted : '-'.$formatted;
    }
}
