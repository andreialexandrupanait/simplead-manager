<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
