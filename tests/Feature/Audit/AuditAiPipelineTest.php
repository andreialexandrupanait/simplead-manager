<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\SfExports;
use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Services\Audit\Ai\AiCheckEvaluator;
use App\Services\Audit\Ai\AuditAiClient;
use App\Services\Audit\AuditAiPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza D (D3): the AI pipeline that wires page-content → AI eval → AI draft →
 * persisted cards + ai states. Anthropic client faked; no real API, no key.
 */
class AuditAiPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function emptyExports(): SfExports
    {
        // Internal:All absent → the pipeline falls back to the homepage.
        return new SfExports('/fake', [], []);
    }

    /**
     * A fake client: the eval tool flips the first requested check to EXISTA; the
     * draft tool returns nothing (so every gap gets a template fallback card).
     */
    private function fakeClient(): AuditAiClient
    {
        return new class implements AuditAiClient
        {
            public function createMessage(array $params): array
            {
                $toolName = $params['tool_choice']['name'] ?? '';
                $enum = $params['tools'][0]['input_schema']['properties']['evaluari']['items']['properties']['checkKey']['enum']
                    ?? [];

                if ($toolName === AiCheckEvaluator::TOOL_NAME && $enum !== []) {
                    $input = ['evaluari' => [[
                        'checkKey' => $enum[0],
                        'stare' => 'EXISTA',
                        'dovada' => 'Dovada suficient de lungă pentru un verdict pozitiv.',
                        'deVerificat' => false,
                    ]]];
                } elseif ($toolName === AiCheckEvaluator::TOOL_NAME) {
                    $input = ['evaluari' => []];
                } else {
                    $input = ['recomandari' => []]; // rely on the template fallback
                }

                return [
                    'content' => [['type' => 'tool_use', 'name' => $toolName, 'input' => $input]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'tool_use',
                ];
            }
        };
    }

    public function test_the_pipeline_applies_ai_states_and_persists_cards(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>Acasă</title></head><body><h1>Salut</h1></body></html>', 200)]);

        $audit = Audit::factory()->create();

        // Seed one result per check: all unknown, one an NU_EXISTA gap.
        $checks = AuditCheck::all();
        foreach ($checks as $c) {
            AuditCheckResult::create([
                'audit_id' => $audit->id, 'audit_check_id' => $c->id,
                'state' => null, 'state_set_by' => 'auto', 'evidence' => [],
            ]);
        }
        $gap = AuditCheck::where('key', '2.7.2')->firstOrFail();
        AuditCheckResult::where('audit_id', $audit->id)->where('audit_check_id', $gap->id)->update([
            'state' => CheckState::NuExista->value,
            'evidence' => ['affected' => [['url' => 'https://x.ro/a']]],
        ]);

        $summary = (new AuditAiPipeline($this->fakeClient()))->run($audit, $this->emptyExports());

        // Pages collected (homepage fallback).
        $this->assertGreaterThanOrEqual(1, $summary['pages']);

        // At least one previously-unknown check was flipped by the AI.
        $this->assertGreaterThanOrEqual(
            1,
            AuditCheckResult::where('audit_id', $audit->id)->where('state_set_by', 'ai')->count(),
        );

        // The NU_EXISTA gap got a DRAFT_AI card (template fallback, needs verification).
        $card = AuditCard::where('audit_id', $audit->id)->whereJsonContains('check_ids', '2.7.2')->first();
        $this->assertNotNull($card);
        $this->assertSame('DRAFT_AI', $card->validation);
        $this->assertTrue($card->needs_verification);
        $this->assertGreaterThanOrEqual(1, $summary['cards']);
    }

    public function test_regeneration_replaces_draft_ai_cards_but_keeps_human_ones(): void
    {
        Http::fake(['*' => Http::response('<html><body><h1>x</h1></body></html>', 200)]);
        $audit = Audit::factory()->create();
        foreach (AuditCheck::all() as $c) {
            AuditCheckResult::create(['audit_id' => $audit->id, 'audit_check_id' => $c->id, 'state' => null, 'state_set_by' => 'auto', 'evidence' => []]);
        }
        // A human-approved card must survive regeneration.
        $audit->cards()->create([
            'title' => 'Human card', 'team' => 'DEV', 'impact' => 'MARE', 'effort' => 'MIC',
            'recommendation' => 'x', 'evidence_text' => 'x', 'check_ids' => ['2.7.2'], 'payload' => [],
            'validation' => 'APROBAT', 'implementation' => 'NEIMPLEMENTAT', 'needs_verification' => false,
            'auto_approved' => false, 'sort_order' => 0,
        ]);

        (new AuditAiPipeline($this->fakeClient()))->run($audit, $this->emptyExports());

        $this->assertSame(1, AuditCard::where('audit_id', $audit->id)->where('validation', 'APROBAT')->count());
    }
}
