<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $google_id
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property \Illuminate\Support\Carbon|null $token_expires_at
 * @property array|null $scopes
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $sites_using
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AnalyticsConnection> $analyticsConnections
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SearchConsoleConnection> $searchConsoleConnections
 */
class GoogleConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'google_id',
        'email',
        'name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function analyticsConnections(): HasMany
    {
        return $this->hasMany(AnalyticsConnection::class);
    }

    public function searchConsoleConnections(): HasMany
    {
        return $this->hasMany(SearchConsoleConnection::class);
    }

    public function sitesCount(): int
    {
        return ($this->analytics_connections_count ?? $this->analyticsConnections()->count())
             + ($this->search_console_connections_count ?? $this->searchConsoleConnections()->count());
    }
}
