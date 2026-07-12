<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeoAuditStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAudit extends Model
{
    protected $fillable = ['site_id', 'score', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count', 'scan_duration', 'pages_crawled', 'seo_plugin', 'seo_plugin_version', 'data', 'scanned_at', 'status', 'error_message', 'category_scores', 'sitemap_urls_count', 'security_headers', 'ssl_info', 'redirect_info', 'robots_txt_data', 'broken_links_count', 'broken_images_count', 'total_images_count', 'redirect_pages_count'];

    protected function casts(): array
    {
        return ['data' => 'array', 'category_scores' => 'array', 'security_headers' => 'array', 'ssl_info' => 'array', 'redirect_info' => 'array', 'robots_txt_data' => 'array', 'scanned_at' => 'datetime', 'status' => SeoAuditStatus::class];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(SeoPage::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SeoIssue::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(SeoLink::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SeoImage::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SeoAuditStatus::Completed);
    }

    /**
     * P2-18: the newest COMPLETED audit for a site.
     *
     * Ordering plain `scanned_at DESC` in PostgreSQL sorts NULLs FIRST, so a
     * completed-but-undated audit (or otherwise null scanned_at) would win over
     * a genuinely newer, finished one — making broken-link suggestions read from
     * the wrong audit. Force NULLS LAST and tie-break on id so the most recent
     * finished audit is always selected.
     */
    public function scopeLatestCompleted(Builder $query): Builder
    {
        return $query->where('status', SeoAuditStatus::Completed)
            ->orderByRaw('scanned_at DESC NULLS LAST')
            ->orderByDesc('id');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [SeoAuditStatus::Pending, SeoAuditStatus::Crawling, SeoAuditStatus::Analyzing, SeoAuditStatus::Scoring]);
    }

    public function isRunning(): bool
    {
        return $this->status->isRunning();
    }

    public function totalIssues(): int
    {
        return $this->critical_count + $this->high_count + $this->medium_count + $this->low_count + $this->info_count;
    }

    public function markAs(SeoAuditStatus $status, ?string $errorMessage = null): void
    {
        $d = ['status' => $status];
        if ($errorMessage !== null) {
            $d['error_message'] = $errorMessage;
        } if ($status === SeoAuditStatus::Completed) {
            $d['scanned_at'] = now();
        } $this->update($d);
    }
}
