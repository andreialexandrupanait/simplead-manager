<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faza 5.3 — one broken-link scan of a site. Holds the run totals and is the anchor
 * for a full snapshot of per-URL results, which the next run diffs against.
 *
 * @property int $id
 * @property int $site_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int $total_urls
 * @property int $broken_count
 * @property int $chains_count
 * @property int $new_broken_count
 * @property int $resolved_count
 * @property-read \App\Models\Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LinkCheckResult> $results
 */
class LinkCheckRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'started_at',
        'finished_at',
        'total_urls',
        'broken_count',
        'chains_count',
        'new_broken_count',
        'resolved_count',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_urls' => 'integer',
        'broken_count' => 'integer',
        'chains_count' => 'integer',
        'new_broken_count' => 'integer',
        'resolved_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LinkCheckResult::class, 'run_id');
    }
}
