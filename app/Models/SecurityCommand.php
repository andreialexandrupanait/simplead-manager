<?php

namespace App\Models;

use App\Enums\SecurityCategory;
use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityCommand extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'category',
        'action',
        'payload',
        'priority',
    ];

    protected $casts = [
        'category' => SecurityCategory::class,
        'payload' => 'array',
        'result' => 'array',
        'status' => SecurityCommandStatus::class,
        'priority' => SecurityCommandPriority::class,
        'picked_up_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SecurityCommandStatus::Pending);
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', SecurityCommandStatus::PickedUp)
            ->where('picked_up_at', '<', now()->subMinutes(30));
    }

    public function markPickedUp(): void
    {
        $this->status = SecurityCommandStatus::PickedUp;
        $this->picked_up_at = now();
        $this->attempts = $this->attempts + 1;
        $this->save();
    }

    public function markCompleted(array $result = []): void
    {
        $this->status = SecurityCommandStatus::Completed;
        $this->completed_at = now();
        $this->result = $result;
        $this->save();
    }

    public function markFailed(array $result = []): void
    {
        $this->status = $this->shouldRetry() ? SecurityCommandStatus::Pending : SecurityCommandStatus::Failed;
        $this->completed_at = now();
        $this->result = $result;
        $this->save();
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }
}
