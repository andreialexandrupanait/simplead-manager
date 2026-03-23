<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchConsoleConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'google_connection_id',
        'property_url',
        'property_type',
        'permission_level',
        'is_active',
        'last_sync_at',
        'next_sync_at',
        'interval_minutes',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'next_sync_at' => 'datetime',
        'interval_minutes' => 'integer',
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
