<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\PsiOpportunity;
use App\DTOs\Audit\PsiRunResult;
use App\DTOs\Audit\V2Eval;
use App\Enums\CheckState;

/**
 * The PSI (PageSpeed Insights) evaluators — 3.5 modern images, 3.6 lazy-load.
 * Port of the PSI section of src/lib/evaluation/v2/evaluators.ts.
 *
 * Thresholds are conservative: ambiguous data stays state=null (manual verdict).
 */
final class PsiEvaluator
{
    /** Below this the audit's savings are negligible → positive evidence (EXISTA). */
    public const PSI_IMG_NEGLIJABIL_OCTETI = 10 * 1024; // 10 KiB

    /** At/above this the savings are a clear problem → NU_EXISTA. */
    public const PSI_IMG_SEMNIFICATIV_OCTETI = 50 * 1024; // 50 KiB

    /** Google's "good" LCP threshold (field/lab). */
    public const PSI_LCP_BUN_MS = 2500;

    /**
     * PSI results for 3.5 and 3.6. When PSI is unavailable (missing key / quota /
     * error), both get state=null with the given note.
     *
     * @return array<string, V2Eval>
     */
    public static function evaluatePsiChecks(?PsiRunResult $psi, ?string $unavailableNote = null): array
    {
        if ($psi === null) {
            $note = $unavailableNote ?? 'PSI indisponibil — verificarea se completează manual.';

            return ['3.5' => new V2Eval(null, ['note' => $note]), '3.6' => new V2Eval(null, ['note' => $note])];
        }
        $cwv = self::cwvSummary($psi);

        return ['3.5' => self::evalPsi35($psi, $cwv), '3.6' => self::evalPsi36($psi, $cwv)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cwvSummary(PsiRunResult $psi): array
    {
        return [
            'field' => $psi->crux !== null
                ? ['lcp' => $psi->crux['lcp'] ?? null, 'cls' => $psi->crux['cls'] ?? null, 'inp' => $psi->crux['inp'] ?? null, 'overall' => $psi->crux['overall'] ?? null]
                : null,
            'lab' => [
                'lcp' => $psi->lighthouse['lcp'] ?? null,
                'cls' => $psi->lighthouse['cls'] ?? null,
                'tbt' => $psi->lighthouse['tbt'] ?? null,
                'performance' => $psi->lighthouse['performance'] ?? null,
            ],
        ];
    }

    /**
     * @return array{id: string, score: float|null, savingsBytes: int|null, savingsMs: float|null, items: array<int, mixed>}|null
     */
    private static function oppToArray(?PsiOpportunity $o): ?array
    {
        if ($o === null) {
            return null;
        }

        return ['id' => $o->id, 'score' => $o->score, 'savingsBytes' => $o->savingsBytes, 'savingsMs' => $o->savingsMs, 'items' => $o->items];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function oppAffected(PsiRunResult $psi, string $key): array
    {
        $opp = $psi->opportunities[$key] ?? null;
        if ($opp === null) {
            return [];
        }
        $rows = array_map(
            static fn (array $it): array => ['url' => $it['url'], 'wastedBytes' => $it['wastedBytes'] ?? null, 'totalBytes' => $it['totalBytes'] ?? null],
            $opp->items,
        );

        return DeterministicSfEvaluator::capAffected($rows)['affected'];
    }

    /**
     * 3.5 — "Are images served WebP with srcset?" Verdict from the image-delivery
     * audit (image-delivery-insight, fallback modern-image-formats/uses-webp-images).
     *
     * @param  array<string, mixed>  $cwv
     */
    private static function evalPsi35(PsiRunResult $psi, array $cwv): V2Eval
    {
        $img = $psi->opportunities['image-delivery-insight']
            ?? $psi->opportunities['modern-image-formats']
            ?? $psi->opportunities['uses-webp-images']
            ?? null;
        $base = ['cwv' => $cwv, 'oportunitateImagini' => self::oppToArray($img)];
        if ($img === null) {
            return new V2Eval(null, array_merge([
                'note' => 'PSI nu a întors auditul de livrare a imaginilor (image-delivery-insight / '
                    .'modern-image-formats) — verdict manual.',
            ], $base));
        }
        $savings = $img->savingsBytes;
        if ($img->score === 1.0 || ($savings !== null && $savings < self::PSI_IMG_NEGLIJABIL_OCTETI)) {
            return new V2Eval(CheckState::Exista, array_merge([
                'note' => 'Imaginile sunt deja servite în formate moderne/optimizate (WebP/AVIF): auditul PSI '
                    .'de livrare a imaginilor e trecut / economiile sunt neglijabile. Conformitatea `srcset` '
                    .'se confirmă punctual manual pe template-uri.',
                'scor' => $img->score,
                'economiiOcteti' => $savings,
            ], $base));
        }
        if (($savings !== null && $savings >= self::PSI_IMG_SEMNIFICATIV_OCTETI) || ($img->score !== null && $img->score < 0.5)) {
            return new V2Eval(CheckState::NuExista, array_merge([
                'note' => ($savings !== null ? 'PSI raportează ~'.round($savings / 1024).' KiB economii ' : 'PSI semnalează economii ')
                    .'din conversia/optimizarea imaginilor la formate moderne (WebP/AVIF) — imaginile listate nu sunt servite optim.',
                'scor' => $img->score,
                'economiiOcteti' => $savings,
                'affected' => self::oppAffected($psi, $img->id),
            ], $base));
        }

        return new V2Eval(null, array_merge([
            'note' => 'Economii de imagini în zona gri (score 0,5–1 / 10–50 KiB) — verdict lăsat manual (conservator).',
            'scor' => $img->score,
            'economiiOcteti' => $savings,
            'affected' => self::oppAffected($psi, $img->id),
        ], $base));
    }

    /**
     * 3.6 — "Lazy-load ONLY below the fold, with an eager LCP?"
     *
     * @param  array<string, mixed>  $cwv
     */
    private static function evalPsi36(PsiRunResult $psi, array $cwv): V2Eval
    {
        $lcpLazy = $psi->opportunities['lcp-lazy-loaded'] ?? null;
        $offscreen = $psi->opportunities['offscreen-images'] ?? null;
        $lcpMs = ($psi->crux['lcp'] ?? null) ?? ($psi->lighthouse['lcp'] ?? null);

        $discEager = is_array($psi->lcpDiscovery) ? ($psi->lcpDiscovery['eagerlyLoaded'] ?? null) : null;
        $lcpEager = $discEager ?? ($lcpLazy !== null ? ($lcpLazy->score === 1.0) : null);

        $base = [
            'cwv' => $cwv,
            'lcpMs' => $lcpMs,
            'lcpEager' => $lcpEager,
            'lcpDiscovery' => $psi->lcpDiscovery,
            'offscreenImages' => self::oppToArray($offscreen),
        ];
        $offscreenSavings = $offscreen?->savingsBytes;

        if ($lcpEager === null && $offscreen === null) {
            return new V2Eval(null, array_merge([
                'note' => 'PSI nu a întors semnale de lazy-load LCP/sub-fold (lcp-discovery-insight / offscreen-images) '
                    .'— verdict manual.',
            ], $base));
        }

        if ($lcpEager === false) {
            return new V2Eval(CheckState::NuExista, array_merge([
                'note' => 'Elementul LCP este încărcat lazy (loading="lazy") — anti-pattern semnalat de PSI '
                    .'(lcp-discovery-insight/lcp-lazy-loaded). LCP-ul trebuie servit eager (fetchpriority=high + preload).',
                'affected' => self::oppAffected($psi, 'offscreen-images'),
            ], $base));
        }

        if ($offscreenSavings !== null && $offscreenSavings >= self::PSI_IMG_SEMNIFICATIV_OCTETI) {
            return new V2Eval(CheckState::NuExista, array_merge([
                'note' => 'Imagini sub fold încărcate eager (nu lazy): auditul PSI offscreen-images raportează '
                    .'~'.round($offscreenSavings / 1024).' KiB economii din amânarea lor.',
                'affected' => self::oppAffected($psi, 'offscreen-images'),
            ], $base));
        }

        $offscreenPass = $offscreen === null
            || $offscreen->score === 1.0
            || ($offscreenSavings !== null && $offscreenSavings < self::PSI_IMG_NEGLIJABIL_OCTETI);
        $lcpBun = $lcpMs !== null && $lcpMs <= self::PSI_LCP_BUN_MS;
        if ($lcpEager === true && $offscreenPass && $lcpBun) {
            return new V2Eval(CheckState::Exista, array_merge([
                'note' => 'LCP încărcat eager (lcp-discovery-insight/lcp-lazy-loaded trecut), imaginile sub fold '
                    .'amânate și LCP bun (≤ 2,5 s). Acoperirea lazy-load doar sub fold se confirmă punctual manual.',
            ], $base));
        }

        return new V2Eval(null, array_merge([
            'note' => 'Datele PSI nu susțin clar un verdict (LCP eager necunoscut sau valoare LCP slabă) — verdict '
                .'lăsat manual (conservator).',
            'affected' => self::oppAffected($psi, 'offscreen-images'),
        ], $base));
    }
}
