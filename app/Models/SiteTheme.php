<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'slug',
        'name',
        'version',
        'author',
        'author_uri',
        'description',
        'is_active',
        'is_child_theme',
        'parent_theme',
        'has_update',
        'update_version',
        'screenshot_url',
        'auto_update',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_child_theme' => 'boolean',
        'has_update' => 'boolean',
        'auto_update' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
