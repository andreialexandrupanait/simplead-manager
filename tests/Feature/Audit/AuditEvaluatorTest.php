<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\SfExportFile;
use App\DTOs\Audit\SfExports;
use App\Enums\CheckState;
use App\Services\Audit\AuditEvaluator;
use App\Services\Audit\SfExportRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza D (D3b): the evaluation orchestrator — merges SF + fetch + PSI +
 * e-commerce applicability into one verdict per check. Port of evaluateV2Audit.
 */
class AuditEvaluatorTest extends TestCase
{
    /** All-absent synthetic exports (skip-empty → SF filters read as empty). */
    private function emptyExports(): SfExports
    {
        $files = [];
        foreach (array_merge(SfExportRegistry::EXPORT_TABS, SfExportRegistry::BULK_EXPORTS, SfExportRegistry::SAVE_REPORTS) as $label) {
            $files[$label] = new SfExportFile($label, requested: true, present: false, fileName: null, rows: [], parseTruncated: false);
        }

        return new SfExports('/fake', $files, []);
    }

    /**
     * @param  list<array{key: string, applicability: string|null}>  $checks
     * @return array<string, \App\DTOs\Audit\V2Eval>
     */
    private function evaluateChecks(array $checks, string $profile = 'B2B_SERVICII'): array
    {
        Http::fake(); // fetch checks resolve against a stubbed 200

        return (new AuditEvaluator)->evaluate($this->emptyExports(), 'https://x.ro', $profile, $checks);
    }

    public function test_ecommerce_check_is_nu_se_aplica_off_ecommerce_and_null_on_ecommerce(): void
    {
        $offEcom = $this->evaluateChecks([['key' => '5.10', 'applicability' => 'ecommerce']], 'B2B_SERVICII');
        $this->assertSame(CheckState::NuSeAplica, $offEcom['5.10']->state);
        $this->assertSame('applicability=ecommerce', $offEcom['5.10']->evidence['motiv']);

        $onEcom = $this->evaluateChecks([['key' => '5.10', 'applicability' => 'ecommerce']], 'ECOMMERCE');
        $this->assertNull($onEcom['5.10']->state);
    }

    public function test_sf_backed_check_uses_the_sf_verdict(): void
    {
        // 2.3.1 = H1:Missing/Multiple — both empty → EXISTA.
        $out = $this->evaluateChecks([['key' => '2.3.1', 'applicability' => null]]);
        $this->assertSame(CheckState::Exista, $out['2.3.1']->state);
    }

    public function test_310_merges_the_homepage_headers_as_evidence(): void
    {
        $out = $this->evaluateChecks([['key' => '3.10', 'applicability' => null]]);
        $this->assertArrayHasKey('homepageHeaders', $out['3.10']->evidence);
    }

    public function test_fetch_backed_check_uses_the_fetch_verdict(): void
    {
        // 6.1 robots — a stubbed empty 200 robots.txt allows everyone → EXISTA.
        $out = $this->evaluateChecks([['key' => '6.1', 'applicability' => null]]);
        $this->assertSame(CheckState::Exista, $out['6.1']->state);
    }

    public function test_unsourced_check_is_left_manual(): void
    {
        $out = $this->evaluateChecks([['key' => '1.1.1', 'applicability' => null]]);
        $this->assertNull($out['1.1.1']->state);
        $this->assertStringContainsString('auditor', $out['1.1.1']->evidence['note']);
    }

    public function test_it_returns_a_verdict_for_every_requested_check(): void
    {
        $keys = ['2.3.1', '6.1', '3.10', '5.10', '1.1.1', '3.5', '3.6'];
        $checks = array_map(static fn (string $k): array => [
            'key' => $k,
            'applicability' => $k === '5.10' ? 'ecommerce' : null,
        ], $keys);

        $out = $this->evaluateChecks($checks);

        $this->assertSame($keys, array_keys($out));
    }
}
