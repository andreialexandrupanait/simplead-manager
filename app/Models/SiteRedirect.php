<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $source_path
 * @property string $target_url
 * @property int $status_code
 * @property bool $is_active
 */
class SiteRedirect extends Model
{
    use HasFactory;

    protected $fillable = ['site_id', 'source_path', 'target_url', 'status_code', 'is_active'];

    protected $casts = [
        'status_code' => 'integer',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Normalise a path the same way the connector does: leading slash, no query/trailing slash. */
    public static function normalizePath(string $path): string
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
