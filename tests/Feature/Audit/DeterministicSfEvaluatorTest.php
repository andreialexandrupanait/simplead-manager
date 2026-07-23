<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\SfExportFile;
use App\DTOs\Audit\SfExports;
use App\Enums\CheckState;
use App\Services\Audit\DeterministicSfEvaluator as Evaluator;
use App\Services\Audit\SfExportRegistry;
use Tests\TestCase;

/**
 * Faza D (D3a): the deterministic SF evaluators — port of the combineFilters
 * engine + evaluateSfChecks tests from the audit repo (evaluators.test.ts).
 */
class DeterministicSfEvaluatorTest extends TestCase
{
    /**
     * Build a synthetic SfExports: label → rows; a label absent from $entries is
     * an absent-but-requested file (skip-empty). Port of makeExports().
     *
     * @param  array<string, list<array<string, string>>|null>  $entries
     */
    private function makeExports(array $entries = []): SfExports
    {
        $files = [];
        $labels = array_merge(
            SfExportRegistry::EXPORT_TABS,
            SfExportRegistry::BULK_EXPORTS,
            SfExportRegistry::SAVE_REPORTS,
        );
        foreach ($labels as $label) {
            $rows = $entries[$label] ?? null;
            $files[$label] = new SfExportFile(
                label: $label,
                requested: true,
                present: $rows !== null,
                fileName: $rows !== null ? SfExportRegistry::normalizeName($label).'.csv' : null,
                rows: $rows ?? [],
                parseTruncated: false,
            );
        }

        return new SfExports('/fake', $files, []);
    }

    // --- combineFilters (base semantic) ------------------------------------

    public function test_all_empty_filters_yield_exista_with_skip_empty_note(): void
    {
        $result = Evaluator::combineFilters($this->makeExports(), ['URL:Uppercase', 'URL:Underscores']);

        $this->assertSame(CheckState::Exista, $result->state);
        $this->assertSame(0, $result->evidence['totalAfectate']);
        $this->assertStringContainsString('--skip-empty', $result->evidence['note']);
        $this->assertTrue($result->evidence['filters'][0]['absentAsEmpty']);
    }

    public function test_any_populated_filter_yields_nu_exista_with_affected_urls(): void
    {
        $exports = $this->makeExports([
            'URL:Uppercase' => [['Address' => 'https://x.ro/Mare', 'Indexability' => 'Indexable']],
        ]);
        $result = Evaluator::combineFilters($exports, ['URL:Uppercase', 'URL:Underscores']);

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame(1, $result->evidence['totalAfectate']);
        $this->assertSame([['url' => 'https://x.ro/Mare', 'filter' => 'URL:Uppercase']], $result->evidence['affected']);
        $this->assertFalse($result->evidence['truncated']);
    }

    public function test_indexable_only_spares_non_indexable_rows(): void
    {
        $exports = $this->makeExports([
            'URL:Uppercase' => [
                ['Address' => 'https://x.ro/Redirectat', 'Indexability' => 'Non-Indexable'],
                ['Address' => 'https://x.ro/Mare', 'Indexability' => 'Indexable'],
            ],
        ]);
        $result = Evaluator::combineFilters($exports, ['URL:Uppercase'], ['indexableOnly' => true]);

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame(1, $result->evidence['totalAfectate']);
        $this->assertSame('https://x.ro/Mare', $result->evidence['affected'][0]['url']);
    }

    public function test_affected_list_is_capped_at_500_with_truncation(): void
    {
        $rows = [];
        for ($i = 0; $i < 620; $i++) {
            $rows[] = ['Address' => "https://x.ro/p{$i}"];
        }
        $result = Evaluator::combineFilters($this->makeExports(['URL:Uppercase' => $rows]), ['URL:Uppercase']);

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame(620, $result->evidence['totalAfectate']);
        $this->assertCount(Evaluator::MAX_EVIDENCE_URLS, $result->evidence['affected']);
        $this->assertTrue($result->evidence['truncated']);
    }

    // --- evaluateSfChecks — special cases ----------------------------------

    public function test_213_canonicalised_params_pass_indexable_params_fail(): void
    {
        $passing = Evaluator::evaluateSfChecks($this->makeExports([
            'URL:Parameters' => [
                ['Address' => 'https://x.ro/?utm=1', 'Indexability' => 'Non-Indexable', 'Canonical Link Element 1' => 'https://x.ro/'],
            ],
        ]))['2.1.3'];
        $this->assertSame(CheckState::Exista, $passing->state);
        $this->assertSame(1, $passing->evidence['totalUrlsCuParametri']);

        $failing = Evaluator::evaluateSfChecks($this->makeExports([
            'URL:Parameters' => [['Address' => 'https://x.ro/?p=2', 'Indexability' => 'Indexable']],
        ]))['2.1.3'];
        $this->assertSame(CheckState::NuExista, $failing->state);
    }

    public function test_225_non_200_indexable_fails_absent_internal_is_null(): void
    {
        $internal = [
            ['Address' => 'https://x.ro/', 'Status Code' => '200', 'Indexability' => 'Indexable', 'Content Type' => 'text/html'],
            ['Address' => 'https://x.ro/rupt', 'Status Code' => '404', 'Indexability' => 'Indexable', 'Content Type' => 'text/html'],
            ['Address' => 'https://x.ro/redir', 'Status Code' => '301', 'Indexability' => 'Non-Indexable', 'Content Type' => 'text/html'],
        ];
        $result = Evaluator::evaluateSfChecks($this->makeExports(['Internal:All' => $internal]))['2.2.5'];
        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame([['url' => 'https://x.ro/rupt', 'status' => '404', 'statusText' => null]], $result->evidence['affected']);

        $missing = Evaluator::evaluateSfChecks($this->makeExports())['2.2.5'];
        $this->assertNull($missing->state);
    }

    public function test_226_html_200_with_zero_inlinks_is_orphan(): void
    {
        $internal = [
            ['Address' => 'https://x.ro/', 'Status Code' => '200', 'Indexability' => 'Indexable', 'Content Type' => 'text/html; charset=UTF-8', 'Unique Inlinks' => '4'],
            ['Address' => 'https://x.ro/orfana', 'Status Code' => '200', 'Indexability' => 'Indexable', 'Content Type' => 'text/html; charset=UTF-8', 'Unique Inlinks' => '0'],
        ];
        $result = Evaluator::evaluateSfChecks($this->makeExports(['Internal:All' => $internal]))['2.2.6'];
        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame(['https://x.ro/orfana'], array_column($result->evidence['affected'], 'url'));
    }

    public function test_275_pagination_title_prefix_states(): void
    {
        $this->assertSame(CheckState::NuSeAplica, Evaluator::evaluateSfChecks($this->makeExports())['2.7.5']->state);

        $base = ['Pagination:Paginated 2+ Pages' => [['Address' => 'https://x.ro/blog/page/2']]];

        $bad = Evaluator::evaluateSfChecks($this->makeExports($base + [
            'Internal:All' => [['Address' => 'https://x.ro/blog/page/2', 'Title 1' => 'Blog - X.ro']],
        ]))['2.7.5'];
        $this->assertSame(CheckState::NuExista, $bad->state);
        $this->assertSame('Blog - X.ro', $bad->evidence['affected'][0]['title']);

        $good = Evaluator::evaluateSfChecks($this->makeExports($base + [
            'Internal:All' => [['Address' => 'https://x.ro/blog/page/2', 'Title 1' => 'Pagina 2 - Blog - X.ro']],
        ]))['2.7.5'];
        $this->assertSame(CheckState::Exista, $good->state);
    }

    public function test_2114_structured_data_precondition_and_errors(): void
    {
        $none = Evaluator::evaluateSfChecks($this->makeExports())['2.11.4'];
        $this->assertNull($none->state);
        $this->assertStringContainsString('precondiție', $none->evidence['note']);

        $ok = Evaluator::evaluateSfChecks($this->makeExports([
            'Structured Data:Contains Structured Data' => [['Address' => 'https://x.ro/', 'Total Types' => '2']],
        ]))['2.11.4'];
        $this->assertSame(CheckState::Exista, $ok->state);

        $bad = Evaluator::evaluateSfChecks($this->makeExports([
            'Structured Data:Contains Structured Data' => [['Address' => 'https://x.ro/', 'Total Types' => '2']],
            'Structured Data:Validation Errors' => [['Address' => 'https://x.ro/', 'Errors' => '3']],
        ]))['2.11.4'];
        $this->assertSame(CheckState::NuExista, $bad->state);
        $this->assertSame('3', $bad->evidence['affected'][0]['errors']);
    }

    public function test_2121_pagination_non_indexable_fails(): void
    {
        $this->assertSame(CheckState::NuSeAplica, Evaluator::evaluateSfChecks($this->makeExports())['2.12.1']->state);

        $result = Evaluator::evaluateSfChecks($this->makeExports([
            'Pagination:Paginated 2+ Pages' => [['Address' => 'https://x.ro/blog/page/2']],
            'Pagination:Non-Indexable' => [[
                'Address' => 'https://x.ro/blog/page/2',
                'Indexability Status' => 'Canonicalised',
                'Canonical Link Element 1' => 'https://x.ro/blog',
            ]],
        ]))['2.12.1'];
        $this->assertSame(CheckState::NuExista, $result->state);
    }

    public function test_manual_checks_with_sf_context_stay_null(): void
    {
        $results = Evaluator::evaluateSfChecks($this->makeExports());
        foreach (['2.3.2', '2.7.1', '3.5', '3.9', '6.3'] as $key) {
            $this->assertNull($results[$key]->state, $key);
        }
    }
}
