<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenancePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_default',
        'sort_order',
        'security_settings',
        'tweak_settings',
        'include_modules',
        'include_security',
        'include_tweaks',
        'source_site_id',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'security_settings' => 'array',
        'tweak_settings' => 'array',
        'include_modules' => 'boolean',
        'include_security' => 'boolean',
        'include_tweaks' => 'boolean',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'maintenance_plan_id');
    }

    public function planModules(): HasMany
    {
        return $this->hasMany(MaintenancePlanModule::class);
    }

    public function sourceSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getEnabledModuleKeysAttribute(): array
    {
        return $this->planModules
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->all();
    }

    public function hasSecuritySettings(): bool
    {
        return $this->include_security && ! empty($this->security_settings);
    }

    public function hasTweakSettings(): bool
    {
        return $this->include_tweaks && ! empty($this->tweak_settings);
    }

    public function hasModuleConfig(): bool
    {
        return $this->include_modules && $this->planModules->isNotEmpty();
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
