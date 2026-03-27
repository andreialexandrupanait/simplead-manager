<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $language
 * @property bool $show_security
 * @property bool $show_cloudflare
 * @property string|null $custom_notes
 * @property array|null $custom_recommendations
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SiteReportConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'language',
        'show_security',
        'show_cloudflare',
        'custom_notes',
    ];

    protected $casts = [
        'show_security' => 'boolean',
        'show_cloudflare' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
