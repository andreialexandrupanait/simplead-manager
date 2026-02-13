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
        'modules',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'modules' => 'array',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'applied_preset_id');
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
