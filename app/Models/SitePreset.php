<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'applied_preset_id');
    }

    public function presetModules(): HasMany
    {
        return $this->hasMany(SitePresetModule::class);
    }

    public function getEnabledModuleKeysAttribute(): array
    {
        return $this->presetModules
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->all();
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
