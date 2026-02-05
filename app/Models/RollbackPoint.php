<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RollbackPoint extends Model
{
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

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')->where('expires_at', '>', now());
    }
}
