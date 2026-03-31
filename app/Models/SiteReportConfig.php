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
 * @property string|null $custom_notes
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
        'custom_notes',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
