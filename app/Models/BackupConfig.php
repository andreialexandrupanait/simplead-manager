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
 * @property int|null $secondary_storage_destination_id
 * @property bool $use_streaming
 * @property string $retention_type
 * @property int $retention_value
 * @property bool $backup_before_updates
 * @property \Illuminate\Support\Carbon|null $last_backup_at
 * @property \Illuminate\Support\Carbon|null $next_backup_at
 * @property string|null $last_backup_status
 * @property string|null $incremental_frequency
 * @property int|null $full_backup_day_of_week
 * @property \Illuminate\Support\Carbon|null $last_full_backup_at
 * @property \Illuminate\Support\Carbon|null $stale_alert_sent_at
 * @property \App\Enums\BackupEngine $backup_engine
 * @property int|null $db_time_budget_seconds
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
        'secondary_storage_destination_id',
        'exclude_paths',
        'exclude_tables',
        'use_streaming',
        'retention_type',
        'retention_value',
        'backup_before_updates',
        'last_backup_at',
        'next_backup_at',
        'last_backup_status',
        'incremental_frequency',
        'full_backup_day_of_week',
        'last_full_backup_at',
        'stale_alert_sent_at',
        'backup_engine',
        'db_time_budget_seconds',
    ];

    protected $casts = [
        'backup_engine' => \App\Enums\BackupEngine::class,
        'db_time_budget_seconds' => 'integer',
        'is_enabled' => 'boolean',
        'use_streaming' => 'boolean',
        'backup_before_updates' => 'boolean',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'exclude_paths' => 'array',
        'exclude_tables' => 'array',
        'retention_value' => 'integer',
        'last_backup_at' => 'datetime',
        'next_backup_at' => 'datetime',
        'full_backup_day_of_week' => 'integer',
        'last_full_backup_at' => 'datetime',
        'stale_alert_sent_at' => 'datetime',
    ];

    /**
     * A computed run time, expressed the way `next_backup_at` is stored.
     *
     * The column holds a wall clock, not an instant: Eloquent writes a Carbon in whatever timezone
     * that Carbon happens to carry, and BackupDispatcher selects with `next_backup_at <= now()`,
     * where `now()` is in config('app.timezone'). So the only value that means what it says is one
     * expressed in the application's timezone — and the site's own timezone has to be folded in
     * before that, not after, or a site scheduled in one zone fires on another's clock.
     *
     * Getting this wrong is invisible in the data and obvious only in the timestamps: writing the
     * instant as UTC put every Europe/Bucharest site three hours early, so twenty sites configured
     * for 03:00 had been backing up at 00:00 — the busiest hour they could have picked — while the
     * three left on a UTC config were correct, which is exactly the pattern that hides a timezone
     * bug from the person reading the config.
     */
    public static function asStoredRunTime(\DateTimeInterface $at): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($at)->setTimezone(config('app.timezone'));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    public function secondaryStorageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class, 'secondary_storage_destination_id');
    }
}
