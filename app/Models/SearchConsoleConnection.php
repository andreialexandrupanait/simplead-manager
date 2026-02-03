<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchConsoleConnection extends Model
{
    protected $fillable = [
        'site_id',
        'google_connection_id',
        'property_url',
        'property_type',
        'permission_level',
        'is_active',
        'last_sync_at',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function googleConnection(): BelongsTo
    {
        return $this->belongsTo(GoogleConnection::class);
    }
}
