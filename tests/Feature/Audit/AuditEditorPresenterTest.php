<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\CheckState;
use App\Services\Audit\AuditEditorPresenter as P;
use Tests\TestCase;

/**
 * Faza D: the v2 validation-editor presentation logic. Port of the
 * v2-editor.test.ts suite.
 */
class AuditEditorPresenterTest extends TestCase
{
    public function test_group_by_subsection_groups_section_02_in_input_order(): void
    {
        $checks = [
            ['key' => '2.1.1', 'subsection_id' => '2.1', 'subsection_name' => 'URL-uri SEO-friendly'],
            ['key' => '2.1.2', 'subsection_id' => '2.1', 'subsection_name' => 'URL-uri SEO-friendly'],
            ['key' => '2.7.1', 'subsection_id' => '2.7', 'subsection_name' => 'Meta title și meta descriere'],
            ['key' => '2.7.3', 'subsection_id' => '2.7', 'subsection_name' => 'Meta title și meta descriere'],
        ];
        $groups = P::groupBySubsection($checks);

        $this->assertSame(['2.1', '2.7'], array_column($groups, 'id'));
        $this->assertSame('URL-uri SEO-friendly', $groups[0]['name']);
        $this->assertSame(['2.1.1', '2.1.2'], array_column($groups[0]['checks'], 'key'));
        $this->assertCount(2, $groups[1]['checks']);
    }

    public function test_group_by_subsection_puts_sections_without_subsections_in_one_null_group(): void
    {
        $groups = P::groupBySubsection([
            ['key' => '3.1', 'subsection_id' => null, 'subsection_name' => null],
            ['key' => '3.2', 'subsection_id' => null, 'subsection_name' => null],
        ]);

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['id']);
        $this->assertSame(['3.1', '3.2'], array_column($groups[0]['checks'], 'key'));
    }

    public function test_group_by_subsection_empty_list(): void
    {
        $this->assertSame([], P::groupBySubsection([]));
    }

    public function test_section_counter_counts_set_and_remaining_without_scores(): void
    {
        $counter = P::sectionCounter([CheckState::Exista, CheckState::NuExista, null, CheckState::NuSeAplica, null]);
        $this->assertSame(['set' => 3, 'remaining' => 2, 'total' => 5], $counter);
        $this->assertSame(['set' => 0, 'remaining' => 2, 'total' => 2], P::sectionCounter([null, null]));
    }

    public function test_state_labels_have_diacritics(): void
    {
        $this->assertSame('EXISTĂ', CheckState::Exista->label());
        $this->assertSame('NU SE APLICĂ', CheckState::NuSeAplica->label());
    }

    public function test_sort_findings_brings_drafts_up_then_validated_then_rejected(): void
    {
        $findings = [
            ['id' => 'a', 'validation' => 'APROBAT', 'sort_order' => 0],
            ['id' => 'b', 'validation' => 'RESPINS', 'sort_order' => 1],
            ['id' => 'c', 'validation' => 'DRAFT_AI', 'sort_order' => 2],
            ['id' => 'd', 'validation' => 'EDITAT', 'sort_order' => 3],
        ];
        $this->assertSame(['c', 'a', 'd', 'b'], array_column(P::sortFindingsForValidation($findings), 'id'));
    }

    public function test_sort_findings_keeps_report_order_within_a_stage(): void
    {
        $findings = [
            ['validation' => 'DRAFT_AI', 'sort_order' => 5],
            ['validation' => 'DRAFT_AI', 'sort_order' => 1],
        ];
        $this->assertSame([1, 5], array_column(P::sortFindingsForValidation($findings), 'sort_order'));
    }

    public function test_compare_places_unknown_validations_last(): void
    {
        $this->assertLessThan(0, P::compareFindingsForValidation(
            ['validation' => 'DRAFT_AI', 'sort_order' => 0],
            ['validation' => 'NECUNOSCUT', 'sort_order' => 0],
        ));
    }

    public function test_draft_count_counts_only_draft_ai(): void
    {
        $this->assertSame(2, P::draftCount([
            ['validation' => 'DRAFT_AI'], ['validation' => 'APROBAT'], ['validation' => 'DRAFT_AI'], ['validation' => 'RESPINS'],
        ]));
        $this->assertSame(0, P::draftCount([]));
    }

    public function test_next_section_with_drafts_cycles(): void
    {
        $sections = [
            ['id' => 's1', 'draftCount' => 0],
            ['id' => 's2', 'draftCount' => 3],
            ['id' => 's3', 'draftCount' => 0],
            ['id' => 's4', 'draftCount' => 1],
        ];
        $this->assertSame('s2', P::nextSectionWithDrafts($sections, 's1'));
        $this->assertSame('s4', P::nextSectionWithDrafts($sections, 's2'));
        $this->assertSame('s2', P::nextSectionWithDrafts($sections, 's4'));
        $this->assertSame('s2', P::nextSectionWithDrafts($sections, 'necunoscut'));
    }

    public function test_next_section_returns_active_when_only_it_has_drafts(): void
    {
        $only = [['id' => 's1', 'draftCount' => 0], ['id' => 's2', 'draftCount' => 2]];
        $this->assertSame('s2', P::nextSectionWithDrafts($only, 's2'));
    }

    public function test_next_section_none_with_drafts_is_null(): void
    {
        $this->assertNull(P::nextSectionWithDrafts([['id' => 's1', 'draftCount' => 0]], 's1'));
        $this->assertNull(P::nextSectionWithDrafts([], 's1'));
    }

    public function test_summarize_evidence_extracts_figures_and_first_urls(): void
    {
        $affected = [];
        for ($i = 0; $i < 12; $i++) {
            $affected[] = ['url' => "https://x.ro/p{$i}", 'filter' => 'URL:Uppercase'];
        }
        $s = P::summarizeEvidence(['totalAfectate' => 12, 'affected' => $affected, 'truncated' => false, 'note' => 'n']);

        $this->assertSame(12, $s['total']);
        $this->assertCount(P::EVIDENCE_URL_PREVIEW, $s['urls']);
        $this->assertSame('https://x.ro/p0', $s['urls'][0]);
        $this->assertSame(12 - P::EVIDENCE_URL_PREVIEW, $s['more']);
        $this->assertSame('n', $s['note']);
        $this->assertTrue($s['hasEvidence']);
    }

    public function test_summarize_evidence_falls_back_to_affected_length(): void
    {
        $s = P::summarizeEvidence(['affected' => [['url' => 'https://x.ro/a']]]);
        $this->assertSame(1, $s['total']);
        $this->assertSame(['https://x.ro/a'], $s['urls']);
        $this->assertSame(0, $s['more']);
    }

    public function test_summarize_evidence_reads_reason_and_truncated(): void
    {
        $s = P::summarizeEvidence(['reason' => 'Site fără e-commerce.', 'truncated' => true]);
        $this->assertSame('Site fără e-commerce.', $s['reason']);
        $this->assertTrue($s['truncated']);
        $this->assertNull($s['total']);
    }

    public function test_summarize_evidence_null_or_non_object(): void
    {
        $this->assertFalse(P::summarizeEvidence(null)['hasEvidence']);
        $this->assertFalse(P::summarizeEvidence('x')['hasEvidence']);
        $this->assertFalse(P::summarizeEvidence([1, 2])['hasEvidence']);
    }

    public function test_table_rows_prefill_from_extra_fields(): void
    {
        $rows = P::tableRowsFromEvidence(['affected' => [
            ['url' => 'https://x.ro/a', 'filter' => 'Page Titles:Over X Characters', 'Title 1' => 'T', 'length' => 74],
            ['url' => 'https://x.ro/b', 'filter' => 'URL:Uppercase'],
        ]]);

        $this->assertSame([
            ['https://x.ro/a', 'Title 1: T · length: 74', ''],
            ['https://x.ro/b', 'URL:Uppercase', ''],
        ], $rows);
    }

    public function test_table_rows_caps_and_skips_rows_without_url(): void
    {
        $affected = [];
        for ($i = 0; $i < P::TABLE_PREFILL_MAX + 20; $i++) {
            $affected[] = ['url' => "https://x.ro/{$i}"];
        }
        $this->assertCount(P::TABLE_PREFILL_MAX, P::tableRowsFromEvidence(['affected' => $affected]));
        $this->assertSame([], P::tableRowsFromEvidence(['affected' => [['filter' => 'f']]]));
        $this->assertSame([], P::tableRowsFromEvidence(null));
    }

    public function test_short_source_sf_export(): void
    {
        $this->assertSame(
            'SF «URL»: Uppercase, Underscores',
            P::shortSource([['type' => 'sf_export', 'tab' => 'URL', 'filters' => ['Uppercase', 'Underscores'], 'columns' => ['Address']]]),
        );
        $this->assertSame('SF «Canonicals»', P::shortSource([['type' => 'sf_export', 'tab' => 'Canonicals']]));
    }

    public function test_short_source_fetch_manual_gsc_and_overflow(): void
    {
        $this->assertSame('fetch — HTML brut', P::shortSource([['type' => 'fetch', 'note' => 'HTML brut']]));
        $this->assertSame('manual', P::shortSource([['type' => 'manual']]));
        $this->assertSame('GSC — Performance', P::shortSource([['type' => 'gsc', 'note' => 'Performance']]));
        $this->assertSame(
            'SF «URL»: Parameters · SF «Canonicals» +1',
            P::shortSource([
                ['type' => 'sf_export', 'tab' => 'URL', 'filters' => ['Parameters']],
                ['type' => 'sf_export', 'tab' => 'Canonicals'],
                ['type' => 'manual'],
            ]),
        );
    }

    public function test_short_source_reports_bulk_and_invalid(): void
    {
        $this->assertSame(
            'SF raport Reports > Redirects > Redirect Chains',
            P::shortSource([['type' => 'sf_report', 'report' => 'Reports > Redirects > Redirect Chains']]),
        );
        $this->assertSame('SF bulk All Inlinks', P::shortSource([['type' => 'sf_bulk_export', 'export' => 'All Inlinks']]));
        $this->assertSame('', P::shortSource(null));
        $this->assertSame('', P::shortSource([]));
    }
}
