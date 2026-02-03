<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUser extends Model
{
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
    ];

    protected $casts = [
        'wp_user_id' => 'integer',
        'posts_count' => 'integer',
        'registered_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
