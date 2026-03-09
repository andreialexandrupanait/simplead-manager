<?php

namespace App\Models;

use App\Enums\SecurityCategory;
use App\Enums\SecuritySettingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecuritySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'category',
        'setting_key',
        'setting_value',
        'is_enabled',
        'applied_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'category' => SecurityCategory::class,
        'setting_value' => 'array',
        'is_enabled' => 'boolean',
        'applied_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at')->whereNull('failed_at');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('failed_at');
    }

    public function getStatusAttribute(): SecuritySettingStatus
    {
        if ($this->failed_at) {
            return SecuritySettingStatus::Failed;
        }
        if ($this->applied_at) {
            return SecuritySettingStatus::Applied;
        }
        if ($this->is_enabled) {
            return SecuritySettingStatus::Pending;
        }

        return SecuritySettingStatus::NotConfigured;
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }
}
