<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SPEC §11 — a task pulled from app.simplead.ro for this site.
 *
 * Operational fields only: money stays in SAD Hub (SPEC §1).
 *
 * @property int $id
 * @property int $site_id
 * @property string $external_id
 * @property string $name
 * @property string $status
 * @property string|null $task_type
 * @property string|null $assignee_name
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property \Illuminate\Support\Carbon|null $external_updated_at
 * @property \Illuminate\Support\Carbon|null $synced_at
 */
class SiteTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'external_id', 'name', 'status', 'task_type',
        'assignee_name', 'due_date', 'started_at', 'status_changed_at',
        'external_updated_at', 'synced_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'started_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'external_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Work finished inside a period — the figure SPEC §12.2 puts under
     * "Mentenanță efectuată".
     */
    public function scopeCompletedBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('status', 'completed')
            ->whereBetween('status_changed_at', [$from, $to]);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled', 'archived']);
    }
}
