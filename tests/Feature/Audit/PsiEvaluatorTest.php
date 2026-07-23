<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\PsiOpportunity;
use App\DTOs\Audit\PsiRunResult;
use App\Enums\CheckState;
use App\Services\Audit\PsiEvaluator;
use Tests\TestCase;

/**
 * Faza D (D3b): the PSI evaluators (3.5 modern images, 3.6 lazy-load). Port of
 * the evaluatePsiChecks tests from evaluators.test.ts.
 */
class PsiEvaluatorTest extends TestCase
{
    private function opp(string $id, ?float $score = null, ?int $savingsBytes = null): PsiOpportunity
    {
        return new PsiOpportunity($id, $score, $savingsBytes, null, []);
    }

    /**
     * @param  array<string, PsiOpportunity>  $opportunities
     * @param  array{eagerlyLoaded?: bool|null}|null  $lcpDiscovery
     */
    private function psi(array $opportunities, ?array $lcpDiscovery = null, ?float $lcp = 2000.0): PsiRunResult
    {
        return new PsiRunResult(
            lighthouse: ['performance' => 85.0, 'lcp' => $lcp, 'cls' => 0.03, 'tbt' => 120.0, 'fcp' => 1100.0, 'si' => 2500.0],
            crux: null,
            opportunities: $opportunities,
            lcpDiscovery: $lcpDiscovery,
        );
    }

    public function test_psi_unavailable_leaves_both_checks_null_with_the_note(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks(null, 'PSI indisponibil: cotă API epuizată.');

        $this->assertNull($out['3.5']->state);
        $this->assertNull($out['3.6']->state);
        $this->assertStringContainsString('cotă', $out['3.5']->evidence['note']);
    }

    public function test_35_passing_audit_is_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(['modern-image-formats' => $this->opp('modern-image-formats', 1.0, 0)]));
        $this->assertSame(CheckState::Exista, $out['3.5']->state);
    }

    public function test_35_negligible_savings_is_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(['modern-image-formats' => $this->opp('modern-image-formats', 0.9, 5_000)]));
        $this->assertSame(CheckState::Exista, $out['3.5']->state);
    }

    public function test_35_significant_savings_is_nu_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(['modern-image-formats' => $this->opp('modern-image-formats', 0.3, 120_000)]));
        $this->assertSame(CheckState::NuExista, $out['3.5']->state);
    }

    public function test_35_gray_zone_stays_null(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(['modern-image-formats' => $this->opp('modern-image-formats', 0.7, 30_000)]));
        $this->assertNull($out['3.5']->state);
    }

    public function test_36_lcp_loaded_lazy_is_nu_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi([], lcpDiscovery: ['eagerlyLoaded' => false]));
        $this->assertSame(CheckState::NuExista, $out['3.6']->state);
    }

    public function test_36_significant_offscreen_savings_is_nu_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(
            ['offscreen-images' => $this->opp('offscreen-images', 0.4, 90_000)],
            lcpDiscovery: ['eagerlyLoaded' => true],
        ));
        $this->assertSame(CheckState::NuExista, $out['3.6']->state);
    }

    public function test_36_eager_lcp_with_good_metrics_is_exista(): void
    {
        $out = PsiEvaluator::evaluatePsiChecks($this->psi(
            [],
            lcpDiscovery: ['eagerlyLoaded' => true],
            lcp: 2000.0,
        ));
        $this->assertSame(CheckState::Exista, $out['3.6']->state);
    }
}
