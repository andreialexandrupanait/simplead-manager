<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\DraftCheckV2;
use App\DTOs\Audit\DraftFinding;
use App\Enums\CheckState;
use App\Services\Audit\Ai\AiCardDrafter;
use App\Services\Audit\Ai\AuditAiClient;
use Tests\TestCase;

/**
 * Faza D (D3d): the AI card drafter — the anti-fabrication guarantees (no real
 * URLs → no table + manual callout) and the "every gap → a resolution" fallback.
 * Port of draft-v2.ts tests. Anthropic client faked; no real API.
 */
class AiCardDrafterTest extends TestCase
{
    /** @param  list<array<string, mixed>>  $recomandari */
    private function fakeClient(array $recomandari, string $stopReason = 'tool_use'): AuditAiClient
    {
        return new class($recomandari, $stopReason) implements AuditAiClient
        {
            public int $calls = 0;

            /** @param  list<array<string, mixed>>  $recomandari */
            public function __construct(private array $recomandari, private string $stopReason) {}

            public function createMessage(array $params): array
            {
                $this->calls++;

                return [
                    'content' => $this->stopReason === 'refusal' ? [] : [[
                        'type' => 'tool_use',
                        'name' => AiCardDrafter::TOOL_NAME,
                        'input' => ['recomandari' => $this->recomandari],
                    ]],
                    'usage' => ['input_tokens' => 200, 'output_tokens' => 300],
                    'stop_reason' => $this->stopReason,
                ];
            }
        };
    }

    private function gap(string $key, mixed $evidence = null): DraftCheckV2
    {
        return new DraftCheckV2($key, "Întrebare {$key}?", CheckState::NuExista, '2.7', 'Titluri', 'DEV', 'Formula [Keyword] - Brand.ro', $evidence);
    }

    /** @param  list<DraftCheckV2>  $checks */
    private function draft(AuditAiClient $client, array $checks): \App\DTOs\Audit\ModuleDraftResult
    {
        return (new AiCardDrafter($client))->draftModule(
            ['key' => 'seo', 'nr' => '02', 'title' => 'SEO on-site'],
            $checks,
            ['name' => 'X', 'domain' => 'x.ro', 'profile' => 'B2B_SERVICII'],
            'x.ro',
            'https://x.ro',
            null,
        );
    }

    public function test_no_gaps_makes_no_api_call(): void
    {
        $client = $this->fakeClient([]);
        $ok = new DraftCheckV2('2.7.1', 'Q?', CheckState::Exista, null, null, 'DEV', null, null);
        $result = $this->draft($client, [$ok]);

        $this->assertSame(0, $client->calls);
        $this->assertSame([], $result->findings);
    }

    public function test_a_card_with_real_urls_keeps_its_table(): void
    {
        $evidence = ['affected' => [['url' => 'https://x.ro/a'], ['url' => 'https://x.ro/b']]];
        $client = $this->fakeClient([[
            'titlu' => 'Rescrie title-urile',
            'echipa' => 'DEV', 'impact' => 'MARE', 'efort' => 'MEDIU',
            'checkIds' => ['2.7.1'], 'diagnostic' => 'Lipsesc title-uri optime.', 'deVerificat' => false,
            'payload' => [
                'table' => ['columns' => ['URL', 'actual', 'recomandat'], 'rows' => [['https://x.ro/a', 'X', 'Y']], 'note' => null],
                'codeBlocks' => null, 'callouts' => null,
            ],
        ]]);

        $result = $this->draft($client, [$this->gap('2.7.1', $evidence)]);

        $this->assertCount(1, $result->findings);
        $this->assertArrayHasKey('table', $result->findings[0]->payload);
        $this->assertFalse($result->findings[0]->needsVerification);
    }

    public function test_a_card_without_real_urls_drops_the_table_and_flags_manual(): void
    {
        // Gap evidence has no affected URLs → the model's table must be dropped.
        $client = $this->fakeClient([[
            'titlu' => 'Adaugă FAQ',
            'echipa' => 'CONTINUT', 'impact' => 'MEDIU', 'efort' => 'MIC',
            'checkIds' => ['2.7.1'], 'diagnostic' => 'Lipsește secțiunea FAQ.', 'deVerificat' => false,
            'payload' => [
                'table' => ['columns' => ['URL', 'actual'], 'rows' => [['inventat', 'x']], 'note' => null],
                'codeBlocks' => null, 'callouts' => null,
            ],
        ]]);

        $result = $this->draft($client, [$this->gap('2.7.1', ['note' => 'no affected urls'])]);

        $this->assertArrayNotHasKey('table', $result->findings[0]->payload);
        $this->assertTrue($result->findings[0]->needsVerification);
        // A "verify manually" callout is injected.
        $this->assertArrayHasKey('callouts', $result->findings[0]->payload);
    }

    public function test_a_card_covering_no_valid_gap_is_ignored(): void
    {
        $client = $this->fakeClient([[
            'titlu' => 'Ceva',
            'echipa' => 'DEV', 'impact' => 'MARE', 'efort' => 'MARE',
            'checkIds' => ['9.9'], 'diagnostic' => 'x', 'deVerificat' => false,
            'payload' => ['table' => null, 'codeBlocks' => null, 'callouts' => null],
        ]]);

        $result = $this->draft($client, [$this->gap('2.7.1', ['affected' => [['url' => 'https://x.ro/a']]])]);

        $this->assertSame([], $result->findings);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_refusal_returns_empty_and_refused(): void
    {
        $client = $this->fakeClient([], 'refusal');
        $result = $this->draft($client, [$this->gap('2.7.1')]);

        $this->assertTrue($result->refused);
        $this->assertSame([], $result->findings);
    }

    public function test_ensure_every_gap_covered_adds_a_fallback_from_the_template(): void
    {
        $gaps = [$this->gap('2.7.1'), $this->gap('2.7.3')];
        // One AI finding covers only 2.7.1 → 2.7.3 must get a fallback card.
        $aiFinding = new DraftFinding('Card AI', 'DEV', 'MARE', 'MEDIU', 'RIDICAT', 'diag', 'ev', ['2.7.1'], [], false, 0);

        $covered = AiCardDrafter::ensureEveryGapCovered($gaps, [$aiFinding]);

        $this->assertCount(2, $covered);
        $fallback = $covered[1];
        $this->assertSame(['2.7.3'], $fallback->checkIds);
        $this->assertTrue($fallback->needsVerification);
        $this->assertSame('Formula [Keyword] - Brand.ro', $fallback->recommendation);
    }
}
