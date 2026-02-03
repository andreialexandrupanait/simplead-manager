<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleConnection extends Model
{
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
        return $this->analyticsConnections()->count() + $this->searchConsoleConnections()->count();
    }
}
