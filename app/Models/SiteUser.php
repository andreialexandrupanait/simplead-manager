<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $wp_user_id
 * @property string $username
 * @property string|null $email
 * @property string|null $display_name
 * @property string|null $role
 * @property string|null $avatar_url
 * @property int $posts_count
 * @property \Illuminate\Support\Carbon|null $registered_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SiteUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'wp_user_id',
        'username',
        'email',
        'display_name',
        'role',
        'avatar_url',
        'posts_count',
        'registered_at',
        'last_login_at',
        'is_active',
        'synced_at',
    ];

    protected $casts = [
        'wp_user_id' => 'integer',
        'posts_count' => 'integer',
        'registered_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopePrivileged(Builder $query): Builder
    {
        return $query->whereIn('role', ['administrator', 'editor', 'author', 'contributor']);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', 'administrator');
    }
}
