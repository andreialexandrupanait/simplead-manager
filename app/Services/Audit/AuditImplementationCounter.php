<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Audit;

/**
 * The v2 "X of Y recommendations implemented" aggregation — the ONLY aggregation
 * v2 allows (no scores). Port of implementationCounter (src/lib/methodology/v2.ts).
 *
 * `total` counts only human-validated cards (APROBAT/EDITAT); RESPINS and the
 * unvalidated DRAFT_AI are ignored. `implemented` = of those, how many are
 * IMPLEMENTAT. At delivery everything is NEIMPLEMENTAT → {implemented: 0, total: N}.
 */
final class AuditImplementationCounter
{
    /** Validation states that count toward the total. */
    private const VALIDATED = ['APROBAT', 'EDITAT'];

    /**
     * @param  iterable<array{validation: string, implementation: string}>  $findings
     * @return array{implemented: int, total: int}
     */
    public static function count(iterable $findings): array
    {
        $implemented = 0;
        $total = 0;
        foreach ($findings as $f) {
            if (! in_array($f['validation'], self::VALIDATED, true)) {
                continue;
            }
            $total++;
            if ($f['implementation'] === 'IMPLEMENTAT') {
                $implemented++;
            }
        }

        return ['implemented' => $implemented, 'total' => $total];
    }

    /**
     * @return array{implemented: int, total: int}
     */
    public static function forAudit(Audit $audit): array
    {
        return self::count(
            $audit->cards()->get(['validation', 'implementation'])
                ->map(static fn ($c): array => [
                    'validation' => (string) $c->validation,
                    'implementation' => (string) $c->implementation,
                ]),
        );
    }
}
