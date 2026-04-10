<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property string $status
 * @property int $pages_found
 * @property int $pages_crawled
 * @property int $pages_with_issues
 * @property int $errors_count
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $duration_seconds
 * @property array|null $config
 * @property array|null $summary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CrawledPage> $pages
 */
class SiteCrawl extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'site_id',
        'status',
        'pages_found',
        'pages_crawled',
        'pages_with_issues',
        'errors_count',
        'started_at',
        'completed_at',
        'duration_seconds',
        'config',
        'summary',
    ];

    protected $casts = [
        'pages_found' => 'integer',
        'pages_crawled' => 'integer',
        'pages_with_issues' => 'integer',
        'errors_count' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'config' => 'array',
        'summary' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawledPage::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getMaxPagesAttribute(): int
    {
        return $this->config['max_pages'] ?? 500;
    }

    public function getProgressPercentAttribute(): int
    {
        $max = $this->max_pages;

        return $max > 0 ? min(100, (int) round(($this->pages_crawled / $max) * 100)) : 0;
    }
}
