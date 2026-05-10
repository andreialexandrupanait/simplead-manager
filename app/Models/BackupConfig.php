<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property bool $is_enabled
 * @property string $frequency
 * @property string $time
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property string $timezone
 * @property string $type
 * @property int|null $storage_destination_id
 * @property string $retention_type
 * @property int $retention_value
 * @property bool $backup_before_updates
 * @property \Illuminate\Support\Carbon|null $last_backup_at
 * @property \Illuminate\Support\Carbon|null $next_backup_at
 * @property string|null $last_backup_status
 * @property string|null $incremental_frequency
 * @property int|null $full_backup_day_of_week
 * @property \Illuminate\Support\Carbon|null $last_full_backup_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\StorageDestination|null $storageDestination
 */
class BackupConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_enabled',
        'frequency',
        'time',
        'day_of_week',
        'day_of_month',
        'timezone',
        'type',
        'storage_destination_id',
        'retention_type',
        'retention_value',
        'backup_before_updates',
        'last_backup_at',
        'next_backup_at',
        'last_backup_status',
        'incremental_frequency',
        'full_backup_day_of_week',
        'last_full_backup_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'backup_before_updates' => 'boolean',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'retention_value' => 'integer',
        'last_backup_at' => 'datetime',
        'next_backup_at' => 'datetime',
        'full_backup_day_of_week' => 'integer',
        'last_full_backup_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }
}
