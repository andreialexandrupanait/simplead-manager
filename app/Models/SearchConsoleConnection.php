<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $google_connection_id
 * @property string $property_url
 * @property string $property_type
 * @property string|null $permission_level
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property \Illuminate\Support\Carbon|null $next_sync_at
 * @property int $interval_minutes
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\GoogleConnection|null $googleConnection
 */
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
