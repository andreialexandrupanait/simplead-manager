<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza 6 — per-site "do not auto-update" list. A plugin appearing here (with
 * is_risky = true) is forced onto the AwaitApproval route regardless of how
 * small the version bump is. Entries are either flagged automatically by the
 * engine (source = 'auto', e.g. after a bad update) or pinned by a human
 * (source = 'manual').
 *
 * @property int $id
 * @property int $site_id
 * @property string $slug
 * @property string $source 'auto' | 'manual'
 * @property string|null $reason
 * @property bool $is_risky
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SiteRiskyPlugin extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'slug',
        'source',
        'reason',
        'is_risky',
    ];

    protected $casts = [
        'is_risky' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Narrow to a single site + plugin slug — the exact lookup the decision
     * engine performs.
     */
    public function scopeForSitePlugin(Builder $query, int $siteId, string $slug): Builder
    {
        return $query->where('site_id', $siteId)->where('slug', $slug);
    }
}
