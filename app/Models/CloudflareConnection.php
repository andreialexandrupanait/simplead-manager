<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudflareConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'api_token',
        'account_id',
        'account_email',
        'is_valid',
        'last_validated_at',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'is_valid' => 'boolean',
        'last_validated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function siteCloudflare(): HasMany
    {
        return $this->hasMany(SiteCloudflare::class);
    }
}
