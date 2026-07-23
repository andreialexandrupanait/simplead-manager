<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\PsiRunResult;
use App\DTOs\Audit\SfExports;
use App\DTOs\Audit\V2Eval;
use App\Enums\CheckState;

/**
 * The v2 evaluation orchestrator: the SF exports on disk + the fetch-based checks
 * + PSI + e-commerce applicability → one { state, evidence } per check (all 82).
 * Port of evaluateV2Audit + mergePsiWithSf + evaluateEcommerceApplicability
 * (src/lib/evaluation/v2/index.ts + evaluators.ts).
 *
 * Checks with no automated source get state=null (the auditor sets them in the
 * editor) with a note in evidence.
 */
final class AuditEvaluator
{
    private const MANUAL_NOTE = 'Verificare manuală/AI — starea se setează de auditor în editor.';

    public function __construct(
        private readonly FetchChecksEvaluator $fetchEvaluator = new FetchChecksEvaluator,
    ) {}

    /**
     * Evaluate all v2 checks of an audit.
     *
     * @param  list<array{key: string, applicability: string|null}>  $checks  all v2 checks (from audit_checks)
     * @return array<string, V2Eval>
     */
    public function evaluate(
        SfExports $exports,
        string $auditUrl,
        string $clientProfile,
        array $checks,
        ?PsiRunResult $psiMobile = null,
        ?string $psiUnavailableNote = null,
        ?string $notFoundProbePath = null,
    ): array {
        $sfResults = DeterministicSfEvaluator::evaluateSfChecks($exports);
        $psiResults = PsiEvaluator::evaluatePsiChecks($psiMobile, $psiUnavailableNote);
        $fetch = $this->fetchEvaluator->runFetchChecks($auditUrl, $notFoundProbePath);
        $fetchResults = $fetch['results'];
        $homepageHeaders = $fetch['homepageHeaders'];

        $out = [];
        foreach ($checks as $check) {
            $key = $check['key'];
            $applicability = $check['applicability'] ?? null;

            // 5.10–5.13: e-commerce applicability, regardless of other sources.
            if ($applicability === 'ecommerce') {
                $out[$key] = self::evaluateEcommerceApplicability($clientProfile);

                continue;
            }

            // 3.5 / 3.6: PSI drives the verdict; SF evidence (if any) is context.
            $psi = $psiResults[$key] ?? null;
            if ($psi !== null) {
                $out[$key] = self::mergePsiWithSf($psi, $sfResults[$key] ?? null);

                continue;
            }

            $sf = $sfResults[$key] ?? null;
            $fetched = $fetchResults[$key] ?? null;

            if ($fetched !== null && $sf !== null) {
                // Not a v2 case (sources split across keys), but if it happens:
                // the fetch verdict wins, evidence accumulates.
                $out[$key] = new V2Eval($fetched->state, array_merge($sf->evidence, $fetched->evidence));

                continue;
            }
            if ($fetched !== null) {
                $out[$key] = $fetched;

                continue;
            }
            if ($sf !== null) {
                // 3.10: SF verdict + the directly-measured homepage headers, as evidence.
                $out[$key] = $key === '3.10'
                    ? new V2Eval($sf->state, array_merge($sf->evidence, ['homepageHeaders' => $homepageHeaders]))
                    : $sf;

                continue;
            }

            $out[$key] = new V2Eval(null, ['note' => self::MANUAL_NOTE]);
        }

        return $out;
    }

    /**
     * 5.10–5.13 apply only to ECOMMERCE clients. On other profiles the state is
     * automatically NU_SE_APLICA, with the reason in evidence.
     */
    public static function evaluateEcommerceApplicability(string $clientProfile): V2Eval
    {
        if ($clientProfile === 'ECOMMERCE') {
            return new V2Eval(null, ['note' => 'Client ECOMMERCE — verificarea se evaluează manual în editor.']);
        }

        return new V2Eval(CheckState::NuSeAplica, [
            'note' => "Verificare exclusiv e-commerce; profilul clientului este {$clientProfile} — nu se aplică.",
            'motiv' => 'applicability=ecommerce',
        ]);
    }

    /**
     * Merge the PSI verdict (3.5/3.6) with the Screaming Frog context evidence.
     * PSI drives the state and same-named fields; notes are concatenated.
     */
    private static function mergePsiWithSf(V2Eval $psi, ?V2Eval $sf): V2Eval
    {
        if ($sf === null) {
            return $psi;
        }
        $sfEvidence = $sf->evidence;
        $sfNote = $sfEvidence['note'] ?? null;
        unset($sfEvidence['note']);
        $merged = array_merge($sfEvidence, $psi->evidence);
        $psiNote = $psi->evidence['note'] ?? null;
        $merged['note'] = implode(' ', array_filter([$psiNote, $sfNote], static fn ($n): bool => $n !== null && $n !== ''));

        return new V2Eval($psi->state, $merged);
    }
}
