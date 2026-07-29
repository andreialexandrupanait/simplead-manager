<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza 5.3 — one checked URL within a broken-link run. `redirect_chain` holds the
 * ordered hops ({url, status_code}) when the URL redirected; null otherwise.
 *
 * @property int $id
 * @property int $run_id
 * @property string $url
 * @property string|null $source_page
 * @property int|null $status_code
 * @property bool $is_internal
 * @property bool $is_broken
 * @property array<int, array{url: string, status_code: int}>|null $redirect_chain
 * @property-read \App\Models\LinkCheckRun|null $run
 */
class LinkCheckResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'url',
        'source_page',
        'status_code',
        'is_internal',
        'is_broken',
        'redirect_chain',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'is_internal' => 'boolean',
        'is_broken' => 'boolean',
        'redirect_chain' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(LinkCheckRun::class, 'run_id');
    }
}
