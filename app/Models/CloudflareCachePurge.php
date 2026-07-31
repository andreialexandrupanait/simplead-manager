<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_cloudflare_id
 * @property string $type
 * @property array|null $targets
 * @property int|null $purged_by
 * @property \Illuminate\Support\Carbon|null $purged_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\SiteCloudflare|null $siteCloudflare
 * @property-read \App\Models\User|null $purgedBy
 */
/**
 * NOTE: nothing writes this table. It looks like an orphan and is not one — the
 * fleet can purge Cloudflare cache (OperationRegistry 'cloudflare.purge') and
 * that purge is never recorded here, so the history this model exists to hold is
 * simply missing. It is kept rather than deleted because deleting it would erase
 * the intent along with the gap; wiring the purge operation to log here is the
 * fix, not a DROP TABLE.
 */
class CloudflareCachePurge extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_cloudflare_id',
        'type',
        'targets',
        'purged_by',
        'purged_at',
    ];

    protected $casts = [
        'targets' => 'array',
        'purged_at' => 'datetime',
    ];

    public function siteCloudflare(): BelongsTo
    {
        return $this->belongsTo(SiteCloudflare::class);
    }

    public function purgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purged_by');
    }
}
