<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditRunStatus;
use App\Enums\CrawlSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza D (D2b): one crawl/ingest run of an audit — the tracker for the SF crawl
 * job (progress, log, resolved-export manifest, error).
 *
 * @property \App\Enums\CrawlSource $source
 * @property \App\Enums\AuditRunStatus $status
 * @property array<string, int>|null $manifest
 * @property list<string>|null $log
 */
class AuditRun extends Model
{
    protected $fillable = [
        'audit_id', 'source', 'status', 'crawl_dir',
        'started_at', 'finished_at', 'duration_ms', 'manifest', 'log', 'error',
    ];

    protected $casts = [
        'source' => CrawlSource::class,
        'status' => AuditRunStatus::class,
        'manifest' => 'array',
        'log' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
