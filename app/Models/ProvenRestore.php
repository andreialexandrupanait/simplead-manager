<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C-08: one recorded proven-restore run — a site's most recent backup restored
 * into the isolated sandbox WordPress and health-checked. `status` is 'passed'
 * or 'failed'; `checks` holds the per-check results for the UI.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $backup_id
 * @property string $status
 * @property array<string,mixed>|null $checks
 * @property string|null $error
 * @property \Illuminate\Support\Carbon $ran_at
 */
class ProvenRestore extends Model
{
    /** @use HasFactory<\Database\Factories\ProvenRestoreFactory> */
    use HasFactory;

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'site_id',
        'backup_id',
        'status',
        'checks',
        'error',
        'ran_at',
    ];

    protected $casts = [
        'checks' => 'array',
        'ran_at' => 'datetime',
    ];

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Backup, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
