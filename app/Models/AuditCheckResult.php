<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza D: the evaluated state of one check for one audit, with the cited evidence
 * that produced it (anti-fabrication: a verdict without evidence is left null /
 * "de verificat"). `state_set_by` = auto | ai | manual.
 *
 * @property \App\Enums\CheckState|null $state
 * @property array<string,mixed>|null $evidence
 */
class AuditCheckResult extends Model
{
    /** @use HasFactory<\Database\Factories\AuditCheckResultFactory> */
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_check_id', 'state', 'evidence', 'state_set_by', 'collected_at'];

    protected $casts = [
        'state' => CheckState::class,
        'evidence' => 'array',
        'collected_at' => 'datetime',
    ];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /** @return BelongsTo<AuditCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(AuditCheck::class, 'audit_check_id');
    }
}
