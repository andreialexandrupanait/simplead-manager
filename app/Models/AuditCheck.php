<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faza D: one of the 82 methodology checks (seeded from checks.js). `key` is the
 * stable methodology id ("2.1.1"). No score/weight — a check is only ever
 * EXISTA / NU_EXISTA / NU_SE_APLICA per audit.
 *
 * @property string $key
 * @property array<int,mixed> $sources
 * @property array<string,mixed>|null $lenses
 */
class AuditCheck extends Model
{
    protected $fillable = [
        'key', 'section_key', 'section_nr', 'section_name', 'subsection_id',
        'subsection_name', 'question', 'sources', 'team', 'lenses',
        'recommendation_template', 'applicability', 'sort_order',
    ];

    protected $casts = [
        'sources' => 'array',
        'lenses' => 'array',
        'team' => AuditTeam::class,
        'sort_order' => 'integer',
    ];

    /** @return HasMany<AuditCheckResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(AuditCheckResult::class);
    }
}
