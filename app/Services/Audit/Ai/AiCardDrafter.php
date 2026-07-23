<?php

declare(strict_types=1);

namespace App\Services\Audit\Ai;

use App\DTOs\Audit\DraftCheckV2;
use App\DTOs\Audit\DraftFinding;
use App\DTOs\Audit\ModuleDraftResult;
use App\Enums\CheckState;
use RuntimeException;

/**
 * AI drafting of recommendation cards from a module's NU_EXISTA gaps (port of
 * src/lib/ai/draft-v2.ts).
 *
 * ANTI-FABRICATION (in the prompt AND structurally in code):
 *  - `checkIds` is an enum of the module's gap keys (can't cover a non-gap);
 *  - the per-URL table is built ONLY from REAL `affected[].url` evidence — a card
 *    whose covered gaps have no real URLs has its table DROPPED, gets a
 *    "verify manually" callout, and is marked needsVerification;
 *  - a payload component that fails validation is stripped, not fabricated.
 *
 * The "every gap → a resolution" guarantee is `ensureEveryGapCovered`: any gap no
 * AI card covered gets a fallback card from its recommendationTemplate.
 */
final class AiCardDrafter
{
    public const TOOL_NAME = 'propune_recomandari';

    /** Real URLs sent to the model per check (token control). */
    public const MAX_EVIDENCE_URLS_PER_CHECK = 60;

    public const MAX_RECS_PER_MODULE = 8;

    public const FALLBACK_IMPACT = 'MEDIU';

    public const FALLBACK_EFFORT = 'MEDIU';

    private const MANUAL_CALLOUT_TEXT =
        'De verificat manual: dovezile colectate nu conțin URL-uri concrete pentru acest gol, așa că lista per-URL trebuie completată de auditor înainte de publicare.';

    private const SEVERITY_FROM_IMPACT = ['MARE' => 'RIDICAT', 'MEDIU' => 'MEDIU', 'MIC' => 'SCAZUT'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ești auditorul AI al agenției Simplead. Primești, pentru UN modul al unui audit v2, verificările marcate NU EXISTĂ (golurile) cu dovezile lor reale. Produci carduri de recomandare exclusiv prin unealta `propune_recomandari`.

        CE ESTE UN CARD BUN (metodologia v2, cap. „Cardul de recomandare"):
        - titlu IMPERATIV și concret (ex. „Rescrie cele 7 title-uri în formula «[Keyword] - Brand.ro»"), nu descriptiv;
        - diagnostic: maximum 3 fraze — ce lipsește, pe câte pagini, de ce contează în limbaj de business (nu jargon);
        - tabel per-URL EXHAUSTIV: coloana «actual» este starea reală de pe site, coloana «recomandat» este valoarea FINALĂ, gata de copiat, compusă după formula din câmpul recommendationTemplate al verificării;
        - code-block-uri copy-paste unde e cazul (HTML / JSON-LD / nginx / txt / bash) cu valorile finale scrise;
        - callout-uri de excepție (NOTA / IMPORTANT / OBSERVATIE) doar când adaugă o regulă sau o atenționare;
        - echipa responsabilă: DEV sau CONTINUT; Impact și Efort exclusiv calitativ (MARE/MEDIU/MIC).

        REGULI DURE ANTI-FABRICARE:
        - NU inventa NIMIC. Fiecare rând din tabelul per-URL trebuie să pornească de la un URL REAL din lista `urlActuale` a verificării acoperite. NU inventa URL-uri.
        - Dacă o verificare acoperită nu are URL-uri concrete în `urlActuale`, NU construi tabel pentru ele: lasă payload.table gol (null), pune totul în diagnostic și adaugă un callout care spune ce trebuie verificat manual, apoi setează deVerificat=true.
        - Valorile RECOMANDATE (title nou, alt-text, JSON-LD etc.) le compui tu după recommendationTemplate; valorile ACTUALE vin exclusiv din dovezi.
        - Recomandările se dau DOAR pe golurile primite (checkIds sunt limitate la verificările NU EXISTĂ ale modulului). Nu raporta pentru lucruri OK.
        - O verificare = un gol; grupează în același card doar goluri strâns înrudite (ex. 2.7.1 + 2.7.3 pe title-uri). Maximum 8 recomandări per modul, cele mai importante.
        - Scrie în română cu diacritice, ton profesionist-direct, factual.
        PROMPT;

    public function __construct(
        private readonly AuditAiClient $client,
    ) {}

    /**
     * Draft a module's recommendation cards from its NU_EXISTA gaps: one API call
     * (+ at most one max_tokens retry). No gaps → no call. The "every gap covered"
     * guarantee is applied separately (ensureEveryGapCovered).
     *
     * @param  array{key: string, nr: string, title: string}  $module
     * @param  list<DraftCheckV2>  $checks
     * @param  array{name: string, domain: string, profile: string}  $client
     */
    public function draftModule(array $module, array $checks, array $client, string $domain, string $auditUrl, ?string $contextNotes = null): ModuleDraftResult
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        $gaps = self::gapChecks($checks);
        if ($gaps === []) {
            return new ModuleDraftResult([], $usage, ['modul fără goluri (NU_EXISTA) — fără apel API'], false);
        }

        $gapKeys = array_map(static fn (DraftCheckV2 $c): string => $c->key, $gaps);
        $urlsByCheckKey = [];
        foreach ($gaps as $c) {
            $urlsByCheckKey[$c->key] = self::extractEvidenceUrls($c->evidence);
        }
        $tool = self::buildTool($gapKeys);
        $userText = self::buildUserMessage($module, $checks, $gaps, $client, $domain, $auditUrl, $contextNotes);

        $warnings = [];
        $message = $this->client->createMessage(self::requestParams($userText, $tool));
        $usage['input_tokens'] += $message['usage']['input_tokens'];
        $usage['output_tokens'] += $message['usage']['output_tokens'];

        if ($message['stop_reason'] === 'max_tokens') {
            $warnings[] = 'răspunsul a depășit limita de tokeni — retry cu instrucțiune de concizie';
            $retryText = $userText."\n\nATENȚIE: răspunsul anterior a depășit limita de tokeni. Fii mai concis: diagnostice mai scurte, mai puține carduri (doar cele mai importante), tabele fără rânduri redundante.";
            $message = $this->client->createMessage(self::requestParams($retryText, $tool));
            $usage['input_tokens'] += $message['usage']['input_tokens'];
            $usage['output_tokens'] += $message['usage']['output_tokens'];
            if ($message['stop_reason'] === 'max_tokens') {
                throw new RuntimeException("Modulul {$module['nr']}: răspunsul a depășit limita de tokeni și după retry");
            }
        }

        if ($message['stop_reason'] === 'refusal') {
            $warnings[] = 'modelul a refuzat cererea (stop_reason=refusal) — modul fără draft';

            return new ModuleDraftResult([], $usage, $warnings, true);
        }

        $toolUse = self::findToolUse($message['content']);
        if ($toolUse === null) {
            throw new RuntimeException("Modulul {$module['nr']}: răspunsul nu conține tool_use „".self::TOOL_NAME."” (stop_reason={$message['stop_reason']})");
        }

        $recomandari = self::validateToolInput($toolUse['input'] ?? null, $module['nr']);
        $mapped = self::mapDraftOutput($recomandari, $gapKeys, $urlsByCheckKey);

        return new ModuleDraftResult($mapped['findings'], $usage, array_merge($warnings, $mapped['warnings']), false);
    }

    /** The module's gaps (NU_EXISTA checks). @param  list<DraftCheckV2>  $checks @return list<DraftCheckV2> */
    public static function gapChecks(array $checks): array
    {
        return array_values(array_filter($checks, static fn (DraftCheckV2 $c): bool => $c->state === CheckState::NuExista));
    }

    /** The real affected URLs of a check's evidence (`affected[].url`). @return list<string> */
    public static function extractEvidenceUrls(mixed $evidence): array
    {
        if (! is_array($evidence) || ! isset($evidence['affected']) || ! is_array($evidence['affected'])) {
            return [];
        }
        $urls = [];
        foreach ($evidence['affected'] as $row) {
            $url = is_array($row) ? ($row['url'] ?? null) : null;
            if (is_string($url) && $url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Every gap no AI card covered gets a fallback card from its
     * recommendationTemplate (needsVerification=true — stays DRAFT_AI, never
     * auto-approved). Result: no gap without a resolution.
     *
     * @param  list<DraftCheckV2>  $gaps
     * @param  list<DraftFinding>  $findings
     * @return list<DraftFinding>
     */
    public static function ensureEveryGapCovered(array $gaps, array $findings): array
    {
        $covered = [];
        foreach ($findings as $f) {
            foreach ($f->checkIds as $k) {
                $covered[$k] = true;
            }
        }
        $out = $findings;
        $sortOrder = count($findings);
        foreach ($gaps as $c) {
            if (isset($covered[$c->key])) {
                continue;
            }
            $team = $c->team === 'DEV' ? 'DEV' : 'CONTINUT';
            $template = trim((string) $c->recommendationTemplate);
            $recommendation = $template !== ''
                ? $template
                : "Verificarea {$c->key} („{$c->question}”) a ieșit NU EXISTĂ. Aplică remedierea din metodologie și confirmă manual.";
            $out[] = new DraftFinding(
                title: mb_substr("Rezolvă golul {$c->key}: {$c->question}", 0, 300),
                team: $team,
                impact: self::FALLBACK_IMPACT,
                effort: self::FALLBACK_EFFORT,
                severity: self::SEVERITY_FROM_IMPACT[self::FALLBACK_IMPACT],
                recommendation: $recommendation,
                evidenceText: "Recomandare de rezervă (șablon metodologie) pentru golul {$c->key} — de verificat de auditor.",
                checkIds: [$c->key],
                payload: [],
                needsVerification: true,
                sortOrder: $sortOrder++,
            );
        }

        return $out;
    }

    /**
     * @param  list<array{titlu: string, echipa: string, impact: string, efort: string, checkIds: list<string>, diagnostic: string, deVerificat: bool, payload: array<string, mixed>}>  $recomandari
     * @param  list<string>  $gapKeys
     * @param  array<string, list<string>>  $urlsByCheckKey
     * @return array{findings: list<DraftFinding>, warnings: list<string>}
     */
    public static function mapDraftOutput(array $recomandari, array $gapKeys, array $urlsByCheckKey): array
    {
        $warnings = [];
        $findings = [];

        foreach (array_slice($recomandari, 0, self::MAX_RECS_PER_MODULE) as $rec) {
            $checkIds = array_values(array_filter(array_unique($rec['checkIds']), static fn (string $k): bool => in_array($k, $gapKeys, true)));
            if ($checkIds === []) {
                $warnings[] = "recomandarea „{$rec['titlu']}” nu acoperă niciun gol valid — ignorată";

                continue;
            }

            $availableUrls = [];
            foreach ($checkIds as $k) {
                foreach ($urlsByCheckKey[$k] ?? [] as $u) {
                    $availableUrls[$u] = true;
                }
            }
            $hasRealUrls = $availableUrls !== [];

            $needsVerification = $rec['deVerificat'];
            $rawPayload = [];

            $table = $rec['payload']['table'] ?? null;
            if (is_array($table) && count($table['rows'] ?? []) > 0) {
                if ($hasRealUrls) {
                    $rawPayload['table'] = self::dropNullNote($table);
                } else {
                    $warnings[] = "recomandarea „{$rec['titlu']}”: tabel per-URL eliminat (evidence fără URL-uri reale)";
                    $needsVerification = true;
                }
            }

            $codeBlocks = $rec['payload']['codeBlocks'] ?? null;
            if (is_array($codeBlocks) && $codeBlocks !== []) {
                $rawPayload['codeBlocks'] = array_map(static fn (array $b): array => self::dropNullNote($b), $codeBlocks);
            }

            $callouts = is_array($rec['payload']['callouts'] ?? null) ? $rec['payload']['callouts'] : [];
            if (! $hasRealUrls) {
                $hasManualNote = false;
                foreach ($callouts as $c) {
                    if (preg_match('/verific/i', (string) ($c['text'] ?? '')) === 1) {
                        $hasManualNote = true;
                    }
                }
                if (! $hasManualNote) {
                    $callouts[] = ['type' => 'NOTA', 'text' => self::MANUAL_CALLOUT_TEXT];
                }
            }
            if ($callouts !== []) {
                $rawPayload['callouts'] = array_values($callouts);
            }

            $validated = self::validatePayload($rawPayload);
            foreach ($validated['warnings'] as $w) {
                $warnings[] = "„{$rec['titlu']}”: {$w}";
            }

            $findings[] = new DraftFinding(
                title: $rec['titlu'],
                team: $rec['echipa'],
                impact: $rec['impact'],
                effort: $rec['efort'],
                severity: self::SEVERITY_FROM_IMPACT[$rec['impact']],
                recommendation: $rec['diagnostic'],
                evidenceText: 'Acoperă verificările: '.implode(', ', $checkIds).' (stare NU EXISTĂ).',
                checkIds: $checkIds,
                payload: $validated['payload'],
                needsVerification: $needsVerification,
                sortOrder: count($findings),
            );
        }

        return ['findings' => $findings, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private static function dropNullNote(array $o): array
    {
        if (! array_key_exists('note', $o) || $o['note'] === null) {
            unset($o['note']);
        }

        return $o;
    }

    /**
     * Progressively validate a payload, stripping the components that don't pass
     * (table → codeBlocks → callouts). An empty {} always passes.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, warnings: list<string>}
     */
    private static function validatePayload(array $payload): array
    {
        $warnings = [];
        $order = ['table', 'codeBlocks', 'callouts'];
        $current = $payload;
        for ($i = 0; $i <= count($order); $i++) {
            if (self::payloadValid($current)) {
                return ['payload' => $current, 'warnings' => $warnings];
            }
            $drop = $order[$i] ?? null;
            if ($drop !== null && array_key_exists($drop, $current)) {
                unset($current[$drop]);
                $warnings[] = "componentă „{$drop}” invalidă în payload — eliminată";
            }
        }

        return ['payload' => [], 'warnings' => $warnings];
    }

    /** @param  array<string, mixed>  $p */
    private static function payloadValid(array $p): bool
    {
        if (isset($p['table']) && ! self::validTable($p['table'])) {
            return false;
        }
        if (isset($p['codeBlocks']) && ! self::validCodeBlocks($p['codeBlocks'])) {
            return false;
        }
        if (isset($p['callouts']) && ! self::validCallouts($p['callouts'])) {
            return false;
        }

        return true;
    }

    private static function validTable(mixed $t): bool
    {
        if (! is_array($t)) {
            return false;
        }
        $cols = $t['columns'] ?? null;
        if (! is_array($cols) || count($cols) < 2 || count($cols) > 6) {
            return false;
        }
        foreach ($cols as $c) {
            if (! is_string($c) || trim($c) === '') {
                return false;
            }
        }
        $rows = $t['rows'] ?? null;
        if (! is_array($rows) || count($rows) > 2000) {
            return false;
        }
        foreach ($rows as $r) {
            if (! is_array($r) || count($r) !== count($cols)) {
                return false;
            }
            foreach ($r as $cell) {
                if (! is_string($cell) || mb_strlen($cell) > 2000) {
                    return false;
                }
            }
        }
        if (array_key_exists('note', $t) && $t['note'] !== null && (! is_string($t['note']) || mb_strlen(trim($t['note'])) > 500)) {
            return false;
        }

        return true;
    }

    private static function validCodeBlocks(mixed $c): bool
    {
        if (! is_array($c) || count($c) > 10) {
            return false;
        }
        foreach ($c as $b) {
            if (! is_array($b)
                || ! is_string($b['language'] ?? null) || trim($b['language']) === '' || mb_strlen($b['language']) > 20
                || ! is_string($b['code'] ?? null) || $b['code'] === '' || mb_strlen($b['code']) > 20000) {
                return false;
            }
            if (array_key_exists('note', $b) && $b['note'] !== null && (! is_string($b['note']) || mb_strlen(trim($b['note'])) > 500)) {
                return false;
            }
        }

        return true;
    }

    private static function validCallouts(mixed $c): bool
    {
        if (! is_array($c) || count($c) > 10) {
            return false;
        }
        foreach ($c as $co) {
            if (! is_array($co)
                || ! in_array($co['type'] ?? null, ['NOTA', 'IMPORTANT', 'OBSERVATIE'], true)
                || ! is_string($co['text'] ?? null)) {
                return false;
            }
            $len = mb_strlen(trim($co['text']));
            if ($len < 3 || $len > 1500) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $checkKeys
     * @return array<string, mixed>
     */
    public static function buildTool(array $checkKeys): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];

        return [
            'name' => self::TOOL_NAME,
            'description' => 'Propune cardurile de recomandare pentru golurile (verificările NU EXISTĂ) ale modulului. Obligatoriu pentru orice răspuns.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['recomandari'],
                'properties' => [
                    'recomandari' => [
                        'type' => 'array',
                        'description' => 'Câte un card per gol sau grup coerent de goluri. Maximum 8.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['titlu', 'echipa', 'impact', 'efort', 'checkIds', 'diagnostic', 'deVerificat', 'payload'],
                            'properties' => [
                                'titlu' => ['type' => 'string', 'description' => 'Titlu imperativ, concret.'],
                                'echipa' => ['type' => 'string', 'enum' => ['DEV', 'CONTINUT']],
                                'impact' => ['type' => 'string', 'enum' => ['MARE', 'MEDIU', 'MIC']],
                                'efort' => ['type' => 'string', 'enum' => ['MARE', 'MEDIU', 'MIC']],
                                'checkIds' => ['type' => 'array', 'description' => 'Verificările NU EXISTĂ acoperite (una sau câteva înrudite).', 'items' => ['type' => 'string', 'enum' => $checkKeys]],
                                'diagnostic' => ['type' => 'string', 'description' => 'Maximum 3 fraze: ce lipsește, pe câte pagini, de ce contează.'],
                                'deVerificat' => ['type' => 'boolean', 'description' => 'true dacă tabelul per-URL nu poate fi completat din dovezi (fără URL-uri concrete).'],
                                'payload' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['table', 'codeBlocks', 'callouts'],
                                    'properties' => [
                                        'table' => ['anyOf' => [[
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'required' => ['columns', 'rows', 'note'],
                                            'properties' => [
                                                'columns' => ['type' => 'array', 'description' => '2–6 coloane, ex. URL / actual / recomandat.', 'items' => ['type' => 'string']],
                                                'rows' => ['type' => 'array', 'description' => 'Un rând per URL real; câte o celulă per coloană.', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                                                'note' => $nullableString,
                                            ],
                                        ], ['type' => 'null']]],
                                        'codeBlocks' => ['anyOf' => [[
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['language', 'code', 'note'],
                                                'properties' => ['language' => ['type' => 'string'], 'code' => ['type' => 'string'], 'note' => $nullableString],
                                            ],
                                        ], ['type' => 'null']]],
                                        'callouts' => ['anyOf' => [[
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['type', 'text'],
                                                'properties' => ['type' => ['type' => 'string', 'enum' => ['NOTA', 'IMPORTANT', 'OBSERVATIE']], 'text' => ['type' => 'string']],
                                            ],
                                        ], ['type' => 'null']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{key: string, nr: string, title: string}  $module
     * @param  list<DraftCheckV2>  $checks
     * @param  list<DraftCheckV2>  $gaps
     * @param  array{name: string, domain: string, profile: string}  $client
     */
    public static function buildUserMessage(array $module, array $checks, array $gaps, array $client, string $domain, string $auditUrl, ?string $contextNotes): string
    {
        $confirmate = array_values(array_map(
            static fn (DraftCheckV2 $c): string => $c->key,
            array_filter($checks, static fn (DraftCheckV2 $c): bool => $c->state === CheckState::Exista),
        ));
        $naKeys = array_values(array_map(
            static fn (DraftCheckV2 $c): string => $c->key,
            array_filter($checks, static fn (DraftCheckV2 $c): bool => $c->state === CheckState::NuSeAplica),
        ));

        $goluri = array_map(static function (DraftCheckV2 $c): array {
            $urls = self::extractEvidenceUrls($c->evidence);
            $shown = array_slice($urls, 0, self::MAX_EVIDENCE_URLS_PER_CHECK);

            return [
                'key' => $c->key,
                'question' => $c->question,
                'subsectiune' => $c->subsectionName ?? $c->subsection,
                'echipaImplicita' => $c->team,
                'recommendationTemplate' => $c->recommendationTemplate,
                'urlActuale' => $shown,
                'urlActualeTotal' => count($urls),
                'urlActualeTrunchiat' => count($urls) > count($shown),
                'evidence' => AiCheckEvaluator::truncateEvidenceJson($c->evidence),
            ];
        }, $gaps);

        $payload = [
            'client' => $client,
            'domain' => $domain,
            'auditUrl' => $auditUrl,
            'contextNotes' => $contextNotes,
            'modul' => $module,
            'verificariConfirmate' => $confirmate,
            'verificariNuSeAplica' => $naKeys,
            'goluri' => $goluri,
        ];

        return implode("\n", [
            'Propune cardurile de recomandare pentru golurile modulului de mai jos.',
            '`urlActuale` = URL-urile REALE afectate (din crawl/fetch) — singura sursă pentru coloana «actual» și pentru rândurile tabelului. Fără URL-uri acolo → fără tabel (vezi regulile).',
            '`evidence` este JSON serializat (posibil trunchiat — notat explicit).',
            '',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private static function requestParams(string $userText, array $tool): array
    {
        return [
            'model' => (string) config('audit.ai.model'),
            'max_tokens' => (int) config('audit.ai.draft_max_tokens', 20000),
            'system' => [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
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
     * @return list<array{titlu: string, echipa: string, impact: string, efort: string, checkIds: list<string>, diagnostic: string, deVerificat: bool, payload: array<string, mixed>}>
     */
    private static function validateToolInput(mixed $input, string $moduleNr): array
    {
        if (! is_array($input) || ! isset($input['recomandari']) || ! is_array($input['recomandari'])) {
            throw new RuntimeException("Modulul {$moduleNr}: output invalid de la model — recomandari lipsă sau nu e listă");
        }
        $out = [];
        foreach ($input['recomandari'] as $i => $r) {
            if (! is_array($r)
                || ! is_string($r['titlu'] ?? null) || $r['titlu'] === ''
                || ! in_array($r['echipa'] ?? null, ['DEV', 'CONTINUT'], true)
                || ! in_array($r['impact'] ?? null, ['MARE', 'MEDIU', 'MIC'], true)
                || ! in_array($r['efort'] ?? null, ['MARE', 'MEDIU', 'MIC'], true)
                || ! is_array($r['checkIds'] ?? null)
                || ! is_string($r['diagnostic'] ?? null)
                || ! is_bool($r['deVerificat'] ?? null)
                || ! is_array($r['payload'] ?? null)) {
                throw new RuntimeException("Modulul {$moduleNr}: output invalid de la model — recomandari.{$i}");
            }
            $checkIds = array_values(array_filter($r['checkIds'], static fn ($k): bool => is_string($k)));
            $payload = $r['payload'];
            $out[] = [
                'titlu' => $r['titlu'],
                'echipa' => $r['echipa'],
                'impact' => $r['impact'],
                'efort' => $r['efort'],
                'checkIds' => $checkIds,
                'diagnostic' => $r['diagnostic'],
                'deVerificat' => $r['deVerificat'],
                'payload' => [
                    'table' => $payload['table'] ?? null,
                    'codeBlocks' => $payload['codeBlocks'] ?? null,
                    'callouts' => $payload['callouts'] ?? null,
                ],
            ];
        }

        return $out;
    }
}
