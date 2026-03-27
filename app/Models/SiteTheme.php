<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $slug
 * @property string $name
 * @property string|null $version
 * @property string|null $author
 * @property string|null $author_uri
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_child_theme
 * @property string|null $parent_theme
 * @property bool $has_update
 * @property string|null $update_version
 * @property string|null $screenshot_url
 * @property bool $auto_update
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
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
