<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCheckResult;

/**
 * Faza D5: the run-to-run delta — compares the check states of one audit against
 * an earlier audit of the same target (site/prospect). This is net-new (the
 * methodology clone only sketched it): a gap that closed (NU_EXISTA → EXISTA) is
 * "implemented"; the reverse is a "regression".
 */
final class AuditDeltaService
{
    /**
     * Compare $current against $previous by check state.
     *
     * @return array{
     *     implemented: int,
     *     regressed: int,
     *     changed: int,
     *     unchanged: int,
     *     total: int,
     *     changes: list<array{key: string, from: string|null, to: string|null, kind: string}>
     * }
     */
    public function compare(Audit $current, Audit $previous): array
    {
        $prev = $this->statesByKey($previous);
        $curr = $this->statesByKey($current);

        $keys = array_values(array_unique([...array_keys($prev), ...array_keys($curr)]));
        sort($keys);

        $implemented = 0;
        $regressed = 0;
        $changed = 0;
        $unchanged = 0;
        $changes = [];

        foreach ($keys as $key) {
            $from = $prev[$key] ?? null;
            $to = $curr[$key] ?? null;

            if ($from === $to) {
                $unchanged++;

                continue;
            }

            $kind = $this->classify($from, $to);
            match ($kind) {
                'implemented' => $implemented++,
                'regressed' => $regressed++,
                default => $changed++,
            };
            $changes[] = ['key' => $key, 'from' => $from, 'to' => $to, 'kind' => $kind];
        }

        return [
            'implemented' => $implemented,
            'regressed' => $regressed,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'total' => count($keys),
            'changes' => $changes,
        ];
    }

    private function classify(?string $from, ?string $to): string
    {
        if ($from === CheckState::NuExista->value && $to === CheckState::Exista->value) {
            return 'implemented';
        }
        if ($from === CheckState::Exista->value && $to === CheckState::NuExista->value) {
            return 'regressed';
        }

        return 'changed';
    }

    /**
     * The check states of an audit, keyed by check key. Values are the raw enum
     * string (EXISTA/NU_EXISTA/NU_SE_APLICA) or null (unevaluated).
     *
     * @return array<string, string|null>
     */
    private function statesByKey(Audit $audit): array
    {
        return AuditCheckResult::query()
            ->where('audit_check_results.audit_id', $audit->id)
            ->join('audit_checks', 'audit_checks.id', '=', 'audit_check_results.audit_check_id')
            ->pluck('audit_check_results.state', 'audit_checks.key')
            ->map(static fn ($state): ?string => $state instanceof CheckState ? $state->value : $state)
            ->all();
    }
}
