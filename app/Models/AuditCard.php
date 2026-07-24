<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza D: a drafted recommendation card (one or more NU_EXISTA checks). validation
 * ∈ DRAFT_AI|APROBAT|EDITAT|RESPINS; implementation ∈ IMPLEMENTAT|NEIMPLEMENTAT.
 * Only cards with fully-deterministic sources auto-approve; AI judgement never does.
 *
 * @property array<int,string>|null $check_ids
 * @property array<string,mixed>|null $payload
 */
class AuditCard extends Model
{
    /** @use HasFactory<\Database\Factories\AuditCardFactory> */
    use HasFactory;

    protected $fillable = [
        'audit_id', 'title', 'team', 'impact', 'effort', 'recommendation',
        'evidence_text', 'check_ids', 'payload', 'validation', 'implementation',
        'needs_verification', 'auto_approved', 'sort_order',
    ];

    protected $casts = [
        'check_ids' => 'array',
        'payload' => 'array',
        'needs_verification' => 'boolean',
        'auto_approved' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
