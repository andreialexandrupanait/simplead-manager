<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $type
 * @property string $trigger
 * @property array|null $components
 * @property string $status
 * @property int $progress
 * @property string|null $error_message
 * @property array|null $log
 * @property int|null $storage_destination_id
 * @property string|null $storage_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property string|null $checksum
 * @property array|null $component_sizes
 * @property string|null $app_version
 * @property string|null $laravel_version
 * @property string|null $php_version
 * @property int $sites_count
 * @property int $users_count
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $duration_seconds
 * @property bool $is_locked
 * @property string|null $lock_reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StorageDestination|null $storageDestination
 */
class AppBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'trigger',
        'components',
        'status',
        'progress',
        'error_message',
        'log',
        'storage_destination_id',
        'storage_path',
        'file_name',
        'file_size',
        'checksum',
        'component_sizes',
        'app_version',
        'laravel_version',
        'php_version',
        'sites_count',
        'users_count',
        'started_at',
        'completed_at',
        'duration_seconds',
        'is_locked',
        'lock_reason',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'components' => 'array',
        'log' => 'array',
        'component_sizes' => 'array',
        'is_locked' => 'boolean',
        'file_size' => 'integer',
        'progress' => 'integer',
        'sites_count' => 'integer',
        'users_count' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        if (! $this->file_size) {
            return '—';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'completed' => 'green',
            'in_progress' => 'purple',
            'pending' => 'yellow',
            'degraded' => 'amber',
            'failed' => 'red',
            default => 'gray',
        };
    }

    public function getDurationFormattedAttribute(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        $seconds = $this->duration_seconds;
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }
}
