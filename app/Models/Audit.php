<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Faza D: one audit run of the 82-check methodology against a connected site XOR
 * a prospect (DB CHECK enforces exactly one).
 *
 * @property \App\Enums\AuditStatus $status
 */
class Audit extends Model
{
    /** @use HasFactory<\Database\Factories\AuditFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id', 'prospect_id', 'status', 'url', 'context_notes',
        'methodology_version', 'created_by',
    ];

    protected $casts = ['status' => AuditStatus::class];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Prospect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AuditCheckResult, $this> */
    public function checkResults(): HasMany
    {
        return $this->hasMany(AuditCheckResult::class);
    }

    /** @return HasMany<AuditCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(AuditCard::class);
    }

    /** @return HasMany<AuditRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AuditRun::class);
    }

    /** @return HasOne<AuditRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(AuditRun::class)->latestOfMany();
    }

    /** @return HasOne<AuditReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(AuditReport::class);
    }

    /**
     * The audited target — a managed Site or a sales Prospect.
     */
    public function target(): Site|Prospect|null
    {
        return $this->site ?? $this->prospect;
    }

    /**
     * The most recent earlier audit of the SAME target that has collected results
     * — the baseline for the run-to-run delta (Faza D5). Null when this is the
     * target's first audit.
     */
    public function previousForTarget(): ?self
    {
        return self::query()
            ->where('id', '<', $this->id)
            ->when(
                $this->site_id !== null,
                fn ($q) => $q->where('site_id', $this->site_id),
                fn ($q) => $q->where('prospect_id', $this->prospect_id),
            )
            ->whereHas('checkResults')
            ->latest('id')
            ->first();
    }
}
