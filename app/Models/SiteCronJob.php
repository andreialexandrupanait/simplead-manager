<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCronJob extends Model
{
    protected $fillable = [
        'site_id',
        'hook',
        'schedule',
        'interval',
        'next_run',
        'last_run',
        'arguments',
        'is_disabled',
    ];

    protected $casts = [
        'interval' => 'integer',
        'next_run' => 'datetime',
        'last_run' => 'datetime',
        'arguments' => 'array',
        'is_disabled' => 'boolean',
    ];

    protected static array $friendlyNames = [
        'wp_scheduled_delete' => 'Empty Trash',
        'wp_update_plugins' => 'Check Plugin Updates',
        'wp_update_themes' => 'Check Theme Updates',
        'wp_version_check' => 'Check Core Updates',
        'wp_cron_delete_expired_transients' => 'Delete Expired Transients',
        'wp_scheduled_auto_draft_delete' => 'Delete Auto-Drafts',
        'wp_site_health_scheduled_check' => 'Site Health Check',
        'delete_expired_transients' => 'Delete Expired Transients',
        'recovery_mode_clean_expired_keys' => 'Clean Recovery Keys',
        'wp_https_detection' => 'HTTPS Detection',
        'wp_privacy_delete_old_export_files' => 'Delete Old Export Files',
    ];

    protected static array $friendlySchedules = [
        'hourly' => 'Every hour',
        'twicedaily' => 'Twice daily',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getFriendlyNameAttribute(): string
    {
        return static::$friendlyNames[$this->hook] ?? $this->hook;
    }

    public function getFriendlyScheduleAttribute(): string
    {
        if ($this->schedule && isset(static::$friendlySchedules[$this->schedule])) {
            return static::$friendlySchedules[$this->schedule];
        }

        if ($this->interval) {
            if ($this->interval < 3600) {
                return 'Every ' . round($this->interval / 60) . ' min';
            }
            if ($this->interval < 86400) {
                return 'Every ' . round($this->interval / 3600) . ' hr';
            }
            return 'Every ' . round($this->interval / 86400) . ' day(s)';
        }

        return $this->schedule ?? 'One-time';
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->next_run && $this->next_run->isPast();
    }
}
