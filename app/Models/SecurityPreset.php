<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SecurityPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'settings',
        'is_default',
        'version',
        'created_by',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_default' => 'boolean',
        'version' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'security_preset_site')
            ->withPivot('applied_at', 'applied_version');
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
