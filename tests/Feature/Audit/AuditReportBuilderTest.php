<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Services\Audit\AuditReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D6: the public report assembly — only validated cards, no scores.
 */
class AuditReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_includes_only_validated_cards_grouped_by_section(): void
    {
        $audit = Audit::factory()->create();
        $key = (string) AuditCheck::query()->orderBy('sort_order')->value('key');

        AuditCard::factory()->for($audit)->validation('APROBAT')->create(['title' => 'Recomandare validată', 'check_ids' => [$key], 'implementation' => 'IMPLEMENTAT']);
        AuditCard::factory()->for($audit)->validation('EDITAT')->create(['title' => 'Recomandare editată', 'check_ids' => [$key]]);
        AuditCard::factory()->for($audit)->validation('DRAFT_AI')->create(['title' => 'Draft nevalidat', 'check_ids' => [$key]]);
        AuditCard::factory()->for($audit)->validation('RESPINS')->create(['title' => 'Respinsă', 'check_ids' => [$key]]);

        $report = (new AuditReportBuilder)->build($audit);

        $titles = collect($report['sections'])->flatMap(fn (array $s): array => array_column($s['cards'], 'title'))->all();
        $this->assertContains('Recomandare validată', $titles);
        $this->assertContains('Recomandare editată', $titles);
        $this->assertNotContains('Draft nevalidat', $titles);
        $this->assertNotContains('Respinsă', $titles);

        // Counter: 2 validated, 1 implemented.
        $this->assertSame(['implemented' => 1, 'total' => 2], $report['counter']);
        $this->assertSame($audit->url, $report['client']['url']);
    }

    public function test_implemented_override_wins_over_card_state(): void
    {
        $audit = Audit::factory()->create();
        $key = (string) AuditCheck::query()->value('key');
        $card = AuditCard::factory()->for($audit)->validation('APROBAT')->create(['check_ids' => [$key], 'implementation' => 'NEIMPLEMENTAT']);

        $report = (new AuditReportBuilder)->build($audit, [(string) $card->id => true]);

        $cards = collect($report['sections'])->flatMap(fn (array $s): array => $s['cards']);
        $this->assertTrue($cards->firstWhere('id', (string) $card->id)['implemented']);
    }
}
