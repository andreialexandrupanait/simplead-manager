<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePresetModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_preset_id',
        'module_key',
        'is_enabled',
        'interval_minutes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'interval_minutes' => 'integer',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(SitePreset::class, 'site_preset_id');
    }
}
