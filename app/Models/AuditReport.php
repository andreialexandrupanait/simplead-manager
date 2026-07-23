<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza D: the published public report snapshot (slug + optional token). Republish
 * keeps slug + token stable (client links stay live) and bumps the version.
 *
 * @property array<string,mixed>|null $implemented_state
 */
class AuditReport extends Model
{
    protected $fillable = [
        'audit_id', 'slug', 'access_token', 'token_required', 'version',
        'html', 'implemented_state', 'published_at',
    ];

    protected $casts = [
        'token_required' => 'boolean',
        'version' => 'integer',
        'implemented_state' => 'array',
        'published_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
