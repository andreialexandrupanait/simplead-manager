<?php

declare(strict_types=1);

namespace App\Services\Audit\Ai;

use App\DTOs\Audit\EvalCheckV2;
use App\DTOs\Audit\EvalResultV2;
use App\DTOs\Audit\ModuleEvalResult;
use App\Enums\CheckState;
use RuntimeException;

/**
 * AI evaluation of the STATE of qualitative checks (port of src/lib/ai/evaluate-v2.ts).
 *
 * Given a module's qualitative checks left at state=null (CRO, content, LLM,
 * off-site — the ~48 nothing deterministic touches) + the representative pages'
 * content + existing SF/PSI evidence, it asks the model to evaluate each check
 * STRICTLY from the given content, via a forced strict tool call.
 *
 * ANTI-FABRICATION (critical — in the system prompt AND structurally in code):
 *  - the model evaluates ONLY from the supplied content; every verdict CITES what
 *    it saw (`dovada`);
 *  - can't determine → NECUNOSCUT → state=null + deVerificat=true (no guessing);
 *  - `checkKey` is restricted to the module's qualitative-key enum (the model
 *    can't touch other checks);
 *  - an EXISTA/NU_EXISTA/NU_SE_APLICA verdict without a citation (`dovada` too
 *    short) is structurally demoted to state=null + deVerificat=true.
 */
final class AiCheckEvaluator
{
    public const TOOL_NAME = 'evalueaza_verificarile';

    /** The raw states asked of the model (NECUNOSCUT → null in code). */
    public const EVAL_STATES = ['EXISTA', 'NU_EXISTA', 'NU_SE_APLICA', 'NECUNOSCUT'];

    /** Per-page serialization cap (a second guard over the C1 caps). */
    public const PAGE_JSON_MAX_CHARS = 3500;

    /** Minimum length of a citation to support a positive/negative verdict. */
    public const MIN_DOVADA_LEN = 8;

    public const EVIDENCE_MAX_CHARS = 4096;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ești auditorul AI al agenției Simplead. Primești, pentru UN modul al unui audit v2, verificările CALITATIVE rămase neevaluate (întrebarea fiecăreia) împreună cu CONȚINUTUL REAL extras din paginile reprezentative ale site-ului (headinguri, CTA-uri, formulare, FAQ, prețuri, dovezi sociale, JSON-LD, widget-uri, text vizibil). EVALUEZI starea fiecărei verificări EXCLUSIV pe baza acestui conținut, prin unealta `evalueaza_verificarile`.

        STĂRILE (răspunzi la întrebarea binară a verificării):
        - EXISTA — conținutul furnizat CONFIRMĂ că lucrul verificat există/e prezent.
        - NU_EXISTA — conținutul furnizat arată clar că lipsește (l-ai fi văzut dacă exista, dar nu e).
        - NU_SE_APLICA — verificarea nu are sens pentru acest site (ex. verificare de produs pe un site fără produse).
        - NECUNOSCUT — conținutul furnizat NU permite o judecată (pagina relevantă nu e în eșantion, semnalul nu e observabil din conținut). Aceasta e starea corectă când nu ești sigur.

        REGULI DURE ANTI-INVENTARE (obligatorii):
        - Evaluezi DOAR pe baza conținutului furnizat în mesaj. NU inventa, NU presupune, NU deduce din cunoștințe generale despre brand sau despre cum „ar trebui" să arate site-ul.
        - Fiecare verdict (`dovada`) CITEAZĂ EXACT ce ai văzut în conținut: un citat scurt (heading, text de CTA, întrebare de FAQ, preț, tip JSON-LD) sau o observație factuală despre ce e/nu e în paginile date. Interzis să pretinzi că ai văzut ceva ce nu e în input.
        - Dacă nu poți determina din conținutul disponibil → stare NECUNOSCUT și spune în `dovada` ce ar trebui verificat manual. NU forța EXISTA/NU_EXISTA când nu ai dovadă.
        - Pentru verificările care cer date din surse externe (cont Google Business, GA4, Bing, backlink-uri, prezență pe alte site-uri) și nu se pot deduce din conținutul paginilor → NECUNOSCUT.
        - Dai exact un rezultat pentru FIECARE verificare primită și pentru NICIO alta. `checkKey` e strict din lista dată.
        - Scrie `dovada` în română, factual, scurt (o frază-două).
        PROMPT;

    public function __construct(
        private readonly AuditAiClient $client,
    ) {}

    /**
     * Evaluate a module's qualitative checks: one API call (+ at most one
     * max_tokens retry). No checks → no call.
     *
     * @param  array{key: string, nr: string, title: string}  $module
     * @param  list<EvalCheckV2>  $checks
     * @param  list<array<string, mixed>>  $pages  representative pages' content
     * @param  array{name: string, domain: string, profile: string}  $client
     */
    public function evaluateModule(array $module, array $checks, array $pages, array $client, string $domain, string $auditUrl): ModuleEvalResult
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        if ($checks === []) {
            return new ModuleEvalResult([], $usage, ['modul fără verificări calitative — fără apel API']);
        }

        $qualitativeKeys = array_map(static fn (EvalCheckV2 $c): string => $c->key, $checks);
        $tool = self::buildTool($qualitativeKeys);
        $userText = self::buildUserMessage($module, $checks, $pages, $client, $domain, $auditUrl);

        $warnings = [];
        $message = $this->client->createMessage(self::requestParams($userText, $tool));
        $usage['input_tokens'] += $message['usage']['input_tokens'];
        $usage['output_tokens'] += $message['usage']['output_tokens'];

        if ($message['stop_reason'] === 'max_tokens') {
            $warnings[] = 'răspunsul a depășit limita de tokeni — retry cu instrucțiune de concizie';
            $retryText = $userText."\n\nATENȚIE: răspunsul anterior a depășit limita de tokeni. Fii mai concis: dovezi de o singură frază.";
            $message = $this->client->createMessage(self::requestParams($retryText, $tool));
            $usage['input_tokens'] += $message['usage']['input_tokens'];
            $usage['output_tokens'] += $message['usage']['output_tokens'];
            if ($message['stop_reason'] === 'max_tokens') {
                throw new RuntimeException("Modulul {$module['nr']}: răspunsul a depășit limita de tokeni și după retry");
            }
        }

        $toolUse = self::findToolUse($message['content']);
        if ($toolUse === null) {
            throw new RuntimeException("Modulul {$module['nr']}: răspunsul nu conține tool_use „".self::TOOL_NAME."” (stop_reason={$message['stop_reason']})");
        }

        $evaluari = self::validateToolInput($toolUse['input'] ?? null, $module['nr']);
        $mapped = self::mapOutput($evaluari, $qualitativeKeys);

        return new ModuleEvalResult($mapped['evaluations'], $usage, array_merge($warnings, $mapped['warnings']));
    }

    /**
     * @param  list<string>  $checkKeys
     * @return array<string, mixed>
     */
    public static function buildTool(array $checkKeys): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Raportează starea fiecărei verificări calitative a modulului, evaluată STRICT pe baza conținutului paginilor furnizate. Obligatoriu pentru orice răspuns.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['evaluari'],
                'properties' => [
                    'evaluari' => [
                        'type' => 'array',
                        'description' => 'Câte un rezultat pentru fiecare verificare primită.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['checkKey', 'stare', 'dovada', 'deVerificat'],
                            'properties' => [
                                'checkKey' => ['type' => 'string', 'enum' => $checkKeys, 'description' => 'Cheia verificării (din lista dată).'],
                                'stare' => ['type' => 'string', 'enum' => self::EVAL_STATES, 'description' => 'EXISTA / NU_EXISTA / NU_SE_APLICA din conținut; NECUNOSCUT dacă nu poți determina.'],
                                'dovada' => ['type' => 'string', 'description' => 'Citatul/observația EXACTĂ din conținut care susține verdictul (sau ce trebuie verificat manual).'],
                                'deVerificat' => ['type' => 'boolean', 'description' => 'true dacă verdictul nu e susținut integral de conținut (obligatoriu true pentru NECUNOSCUT).'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function truncateEvidenceJson(mixed $evidence, int $maxChars = self::EVIDENCE_MAX_CHARS): string
    {
        $json = json_encode($evidence ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return 'null';
        }
        if (strlen($json) <= $maxChars) {
            return $json;
        }

        return substr($json, 0, $maxChars).'…[TRUNCHIAT: evidence avea '.strlen($json).' caractere]';
    }

    /**
     * @param  array{key: string, nr: string, title: string}  $module
     * @param  list<EvalCheckV2>  $checks
     * @param  list<array<string, mixed>>  $pages
     * @param  array{name: string, domain: string, profile: string}  $client
     */
    public static function buildUserMessage(array $module, array $checks, array $pages, array $client, string $domain, string $auditUrl): string
    {
        $verificari = array_map(static fn (EvalCheckV2 $c): array => [
            'checkKey' => $c->key,
            'intrebare' => $c->question,
            'subsectiune' => $c->subsectionName ?? $c->subsection,
            'echipaImplicita' => $c->team,
            'tipuriSurse' => $c->sourceTypes,
            'evidentaExistenta' => self::truncateEvidenceJson($c->existingEvidence),
        ], $checks);

        $pagini = array_map(static function (array $p): array {
            $compact = [
                'url' => $p['finalUrl'] ?? null,
                'tip' => $p['classification'] ?? null,
                'status' => $p['status'] ?? null,
                'title' => $p['title'] ?? null,
                'metaDescription' => $p['metaDescription'] ?? null,
                'headings' => $p['headings'] ?? [],
                'ctas' => $p['ctas'] ?? [],
                'formulare' => $p['forms'] ?? [],
                'areFaq' => $p['hasFaq'] ?? false,
                'faqSample' => $p['faqSample'] ?? [],
                'preturi' => $p['prices'] ?? [],
                'doveziSociale' => $p['socialProof'] ?? [],
                'jsonLdTypes' => $p['jsonLdTypes'] ?? [],
                'areChat' => $p['hasChatWidget'] ?? false,
                'areWhatsApp' => $p['hasWhatsApp'] ?? false,
                'textVizibil' => $p['visibleText'] ?? '',
                'lungimeText' => $p['textLength'] ?? 0,
            ];
            $json = json_encode($compact, JSON_UNESCAPED_UNICODE);
            if ($json !== false && strlen($json) <= self::PAGE_JSON_MAX_CHARS) {
                return $compact;
            }
            // Aggressively truncate the visible text if the page is too big.
            $compact['textVizibil'] = mb_substr((string) ($p['visibleText'] ?? ''), 0, 1200);
            $compact['_trunchiat'] = true;

            return $compact;
        }, $pages);

        $payload = [
            'client' => $client,
            'domain' => $domain,
            'auditUrl' => $auditUrl,
            'modul' => $module,
            'verificari' => $verificari,
            'paginiAnalizate' => $pagini,
        ];

        return implode("\n", [
            'Evaluează starea fiecărei verificări calitative de mai jos, STRICT pe baza `paginiAnalizate`.',
            '`paginiAnalizate` este singura sursă de adevăr: dacă un semnal nu apare acolo, folosește NECUNOSCUT — nu presupune.',
            '`evidentaExistenta` este JSON serializat (posibil trunchiat) cu ce a colectat deja crawl-ul pentru verificare.',
            '',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Map the validated output onto results, applying the anti-fabrication rules.
     *
     * @param  list<array{checkKey: string, stare: string, dovada: string, deVerificat: bool}>  $evaluari
     * @param  list<string>  $qualitativeKeys
     * @return array{evaluations: list<EvalResultV2>, warnings: list<string>}
     */
    public static function mapOutput(array $evaluari, array $qualitativeKeys): array
    {
        $warnings = [];
        $evaluations = [];
        $seen = [];

        foreach ($evaluari as $e) {
            $key = $e['checkKey'];
            if (! in_array($key, $qualitativeKeys, true)) {
                $warnings[] = "evaluare pentru cheie necunoscută/non-calitativă „{$key}” — ignorată";

                continue;
            }
            if (isset($seen[$key])) {
                $warnings[] = "evaluare duplicată pentru „{$key}” — păstrată prima";

                continue;
            }
            $seen[$key] = true;

            $dovada = trim($e['dovada']);
            $state = self::stateOf($e['stare']);
            $deVerificat = $e['deVerificat'];

            if ($state === null) {
                // NECUNOSCUT → always to-verify.
                $deVerificat = true;
            } elseif (mb_strlen($dovada) < self::MIN_DOVADA_LEN) {
                // A verdict without a concrete citation is not accepted (anti-fabrication).
                $warnings[] = "„{$key}”: verdict {$e['stare']} fără dovadă citată — retrogradat la null + de verificat";
                $state = null;
                $deVerificat = true;
            }

            $evaluations[] = new EvalResultV2($key, $state, $dovada, $deVerificat);
        }

        foreach ($qualitativeKeys as $key) {
            if (! isset($seen[$key])) {
                $warnings[] = "verificarea „{$key}” nu a primit evaluare de la model";
            }
        }

        return ['evaluations' => $evaluations, 'warnings' => $warnings];
    }

    private static function stateOf(string $stare): ?CheckState
    {
        return match ($stare) {
            'EXISTA' => CheckState::Exista,
            'NU_EXISTA' => CheckState::NuExista,
            'NU_SE_APLICA' => CheckState::NuSeAplica,
            default => null, // NECUNOSCUT / anything unexpected
        };
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private static function requestParams(string $userText, array $tool): array
    {
        return [
            'model' => (string) config('audit.ai.model'),
            'max_tokens' => (int) config('audit.ai.eval_max_tokens', 8000),
            'system' => [[
                'type' => 'text',
                'text' => self::SYSTEM_PROMPT,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
            'messages' => [['role' => 'user', 'content' => $userText]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>|null
     */
    private static function findToolUse(array $content): ?array
    {
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Validate the tool input structurally (the code-level equivalent of the zod
     * schema). Throws on a malformed shape.
     *
     * @return list<array{checkKey: string, stare: string, dovada: string, deVerificat: bool}>
     */
    private static function validateToolInput(mixed $input, string $moduleNr): array
    {
        if (! is_array($input) || ! isset($input['evaluari']) || ! is_array($input['evaluari'])) {
            throw new RuntimeException("Modulul {$moduleNr}: output invalid de la model — evaluari lipsă sau nu e listă");
        }
        $out = [];
        foreach ($input['evaluari'] as $i => $e) {
            if (! is_array($e)
                || ! is_string($e['checkKey'] ?? null)
                || ! is_string($e['stare'] ?? null)
                || ! in_array($e['stare'], self::EVAL_STATES, true)
                || ! is_string($e['dovada'] ?? null)
                || ! is_bool($e['deVerificat'] ?? null)) {
                throw new RuntimeException("Modulul {$moduleNr}: output invalid de la model — evaluari.{$i}");
            }
            $out[] = ['checkKey' => $e['checkKey'], 'stare' => $e['stare'], 'dovada' => $e['dovada'], 'deVerificat' => $e['deVerificat']];
        }

        return $out;
    }
}
