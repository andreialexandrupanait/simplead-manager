<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DTOs\Audit\EvalCheckV2;
use App\Enums\CheckState;
use App\Services\Audit\Ai\AiCheckEvaluator;
use App\Services\Audit\Ai\AuditAiClient;
use Tests\TestCase;

/**
 * Faza D (D3c): the AI evaluator of qualitative check states — the anti-fabrication
 * guarantees especially. Port of evaluate-v2.ts tests. The Anthropic client is
 * faked; no real API, no key.
 */
class AiCheckEvaluatorTest extends TestCase
{
    /**
     * A fake client that returns a single tool_use block with the given evaluari.
     *
     * @param  list<array<string, mixed>>  $evaluari
     */
    private function fakeClient(array $evaluari, string $stopReason = 'tool_use', int $maxTokensTimes = 0): AuditAiClient
    {
        return new class($evaluari, $stopReason, $maxTokensTimes) implements AuditAiClient
        {
            public int $calls = 0;

            /** @param  list<array<string, mixed>>  $evaluari */
            public function __construct(private array $evaluari, private string $stopReason, private int $maxTokensTimes) {}

            public function createMessage(array $params): array
            {
                $this->calls++;
                $stop = $this->calls <= $this->maxTokensTimes ? 'max_tokens' : $this->stopReason;

                return [
                    'content' => [[
                        'type' => 'tool_use',
                        'name' => AiCheckEvaluator::TOOL_NAME,
                        'input' => ['evaluari' => $this->evaluari],
                    ]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                    'stop_reason' => $stop,
                ];
            }
        };
    }

    private function check(string $key): EvalCheckV2
    {
        return new EvalCheckV2($key, "Întrebare {$key}?", '5.1', 'Secțiune', 'MARKETING', ['manual'], null, null);
    }

    /** @param  list<EvalCheckV2>  $checks */
    private function evaluate(AuditAiClient $client, array $checks): \App\DTOs\Audit\ModuleEvalResult
    {
        return (new AiCheckEvaluator($client))->evaluateModule(
            ['key' => 'cro', 'nr' => '05', 'title' => 'CRO'],
            $checks,
            [['finalUrl' => 'https://x.ro', 'classification' => 'home', 'visibleText' => 'Contact us today']],
            ['name' => 'X', 'domain' => 'x.ro', 'profile' => 'B2B_SERVICII'],
            'x.ro',
            'https://x.ro',
        );
    }

    public function test_no_qualitative_checks_makes_no_api_call(): void
    {
        $client = $this->fakeClient([]);
        $result = (new AiCheckEvaluator($client))->evaluateModule(
            ['key' => 'cro', 'nr' => '05', 'title' => 'CRO'], [], [], ['name' => 'X', 'domain' => 'x.ro', 'profile' => 'B2B'], 'x.ro', 'https://x.ro',
        );

        $this->assertSame(0, $client->calls);
        $this->assertSame([], $result->evaluations);
    }

    public function test_a_cited_verdict_is_mapped_to_its_state(): void
    {
        $client = $this->fakeClient([
            ['checkKey' => '5.1', 'stare' => 'EXISTA', 'dovada' => 'Heading „Cere ofertă" prezent pe homepage.', 'deVerificat' => false],
        ]);
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertCount(1, $result->evaluations);
        $this->assertSame(CheckState::Exista, $result->evaluations[0]->state);
        $this->assertFalse($result->evaluations[0]->deVerificat);
    }

    public function test_necunoscut_becomes_null_and_to_verify(): void
    {
        $client = $this->fakeClient([
            ['checkKey' => '5.1', 'stare' => 'NECUNOSCUT', 'dovada' => 'Pagina relevantă nu e în eșantion.', 'deVerificat' => false],
        ]);
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertNull($result->evaluations[0]->state);
        $this->assertTrue($result->evaluations[0]->deVerificat);
    }

    public function test_a_verdict_without_a_citation_is_demoted(): void
    {
        $client = $this->fakeClient([
            ['checkKey' => '5.1', 'stare' => 'EXISTA', 'dovada' => 'da', 'deVerificat' => false], // too short
        ]);
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertNull($result->evaluations[0]->state);
        $this->assertTrue($result->evaluations[0]->deVerificat);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_an_evaluation_for_an_unknown_key_is_ignored(): void
    {
        $client = $this->fakeClient([
            ['checkKey' => '9.9', 'stare' => 'EXISTA', 'dovada' => 'Ceva ce nu ni s-a cerut.', 'deVerificat' => false],
        ]);
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertSame([], $result->evaluations);
        // both: unknown-key ignored + the requested 5.1 got no evaluation
        $this->assertContains('evaluare pentru cheie necunoscută/non-calitativă „9.9” — ignorată', $result->warnings);
        $this->assertContains('verificarea „5.1” nu a primit evaluare de la model', $result->warnings);
    }

    public function test_a_duplicate_evaluation_keeps_the_first(): void
    {
        $client = $this->fakeClient([
            ['checkKey' => '5.1', 'stare' => 'EXISTA', 'dovada' => 'Primul verdict, citat suficient de lung.', 'deVerificat' => false],
            ['checkKey' => '5.1', 'stare' => 'NU_EXISTA', 'dovada' => 'Al doilea verdict, ignorat.', 'deVerificat' => false],
        ]);
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertCount(1, $result->evaluations);
        $this->assertSame(CheckState::Exista, $result->evaluations[0]->state);
    }

    public function test_it_retries_once_on_max_tokens_then_succeeds(): void
    {
        $client = $this->fakeClient(
            [['checkKey' => '5.1', 'stare' => 'EXISTA', 'dovada' => 'Citat suficient de lung pentru verdict.', 'deVerificat' => false]],
            maxTokensTimes: 1,
        );
        $result = $this->evaluate($client, [$this->check('5.1')]);

        $this->assertSame(2, $client->calls);
        $this->assertSame(CheckState::Exista, $result->evaluations[0]->state);
        $this->assertSame(200, $result->usage['input_tokens']); // both calls counted
    }
}
