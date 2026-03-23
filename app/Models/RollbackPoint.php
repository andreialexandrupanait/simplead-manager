<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RollbackPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'type',
        'slug',
        'from_version',
        'to_version',
        'backup_reference',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available')->where('expires_at', '>', now());
    }
}
