<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\DraftCheckV2;
use App\DTOs\Audit\EvalCheckV2;
use App\DTOs\Audit\SfExports;
use App\Enums\ProspectProfile;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Services\Audit\Ai\AiCardDrafter;
use App\Services\Audit\Ai\AiCheckEvaluator;
use App\Services\Audit\Ai\AuditAiClient;
use App\Services\Audit\Http\PageContentCollector;

/**
 * Faza D: the AI tier of an audit run. Runs only when an Anthropic key is present.
 * For each methodology module (section): collects the representative pages' content
 * once, AI-evaluates the still-unknown qualitative checks (updating their state to
 * `ai`), then drafts recommendation cards for the NU_EXISTA gaps and persists them
 * (with the "every gap → a resolution" fallback). Regeneration replaces only the
 * DRAFT_AI cards — human-touched cards (APROBAT/EDITAT/RESPINS) are kept.
 */
final class AuditAiPipeline
{
    public function __construct(
        private readonly AuditAiClient $client,
        private readonly PageContentCollector $pageCollector = new PageContentCollector,
    ) {}

    /**
     * @return array{pages: int, cards: int, input_tokens: int, output_tokens: int, warnings: list<string>}
     */
    public function run(Audit $audit, SfExports $exports): array
    {
        $summary = ['pages' => 0, 'cards' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'warnings' => []];

        $pages = $this->collectPages($audit, $exports);
        $summary['pages'] = count($pages);

        $domain = (string) (parse_url($audit->url, PHP_URL_HOST) ?: $audit->url);
        $profile = $audit->prospect?->profile;
        $target = $audit->target();
        $client = [
            'name' => $target !== null ? (string) $target->name : $domain,
            'domain' => $domain,
            'profile' => $profile instanceof ProspectProfile ? $profile->value : '',
        ];

        $checks = AuditCheck::query()->orderBy('sort_order')->get();
        $resultByCheckId = $audit->checkResults()->get()->keyBy('audit_check_id');

        $evaluator = new AiCheckEvaluator($this->client);
        $drafter = new AiCardDrafter($this->client);

        // Regeneration: drop the AI drafts, keep human-touched cards.
        $audit->cards()->where('validation', 'DRAFT_AI')->delete();

        foreach ($checks->groupBy('section_key') as $sectionChecks) {
            $first = $sectionChecks->first();
            $module = ['key' => $first->section_key, 'nr' => $first->section_nr, 'title' => $first->section_name];

            // 1. AI-evaluate the still-unknown (state=null) qualitative checks.
            $evalChecks = [];
            foreach ($sectionChecks as $c) {
                if (($resultByCheckId[$c->id] ?? null)?->state === null) {
                    $evalChecks[] = new EvalCheckV2(
                        $c->key, $c->question, $c->subsection_id, $c->subsection_name,
                        $c->team?->value, self::sourceTypes($c), $c->recommendation_template,
                        ($resultByCheckId[$c->id] ?? null)?->evidence,
                    );
                }
            }
            $eval = $evaluator->evaluateModule($module, $evalChecks, $pages, $client, $domain, $audit->url);
            $summary['input_tokens'] += $eval->usage['input_tokens'];
            $summary['output_tokens'] += $eval->usage['output_tokens'];
            $summary['warnings'] = array_merge($summary['warnings'], $eval->warnings);

            $byKey = $sectionChecks->keyBy('key');
            foreach ($eval->evaluations as $ev) {
                $chk = $byKey[$ev->checkKey] ?? null;
                $res = $chk !== null ? ($resultByCheckId[$chk->id] ?? null) : null;
                if ($res === null) {
                    continue;
                }
                $evidence = is_array($res->evidence) ? $res->evidence : [];
                $evidence['ai'] = ['dovada' => $ev->dovada, 'deVerificat' => $ev->deVerificat];
                $res->update([
                    'state' => $ev->state,
                    'state_set_by' => $ev->state !== null ? 'ai' : $res->state_set_by,
                    'evidence' => $evidence,
                ]);
            }

            // 2. Draft cards for the module's NU_EXISTA gaps (using the now-current state).
            $draftChecks = [];
            foreach ($sectionChecks as $c) {
                $res = $resultByCheckId[$c->id] ?? null;
                $draftChecks[] = new DraftCheckV2(
                    $c->key, $c->question, $res?->state, $c->subsection_id, $c->subsection_name,
                    $c->team?->value, $c->recommendation_template, $res?->evidence,
                );
            }
            $draft = $drafter->draftModule($module, $draftChecks, $client, $domain, $audit->url, $audit->context_notes);
            $summary['input_tokens'] += $draft->usage['input_tokens'];
            $summary['output_tokens'] += $draft->usage['output_tokens'];
            $summary['warnings'] = array_merge($summary['warnings'], $draft->warnings);

            $findings = AiCardDrafter::ensureEveryGapCovered(AiCardDrafter::gapChecks($draftChecks), $draft->findings);
            foreach ($findings as $f) {
                $audit->cards()->create([
                    'title' => $f->title,
                    'team' => $f->team,
                    'impact' => $f->impact,
                    'effort' => $f->effort,
                    'recommendation' => $f->recommendation,
                    'evidence_text' => $f->evidenceText,
                    'check_ids' => $f->checkIds,
                    'payload' => $f->payload,
                    'validation' => 'DRAFT_AI',
                    'implementation' => 'NEIMPLEMENTAT',
                    'needs_verification' => $f->needsVerification,
                    'auto_approved' => false,
                    'sort_order' => $f->sortOrder,
                ]);
                $summary['cards']++;
            }
        }

        return $summary;
    }

    /**
     * The representative pages' content, from the crawl's Internal:All candidates.
     *
     * @return list<array<string, mixed>>
     */
    private function collectPages(Audit $audit, SfExports $exports): array
    {
        $internal = $exports->exportOf('Internal:All');
        $candidates = array_map(static fn (array $r): array => [
            'url' => $r['Address'] ?? '',
            'status' => isset($r['Status Code']) ? (int) $r['Status Code'] : null,
            'indexable' => ($r['Indexability'] ?? null) === 'Indexable',
            'isHtml' => str_starts_with($r['Content Type'] ?? '', 'text/html'),
        ], $internal->rows);

        $urls = PageContentCollector::selectRepresentativePages($candidates, $audit->url);

        return array_map(fn (string $u): array => $this->pageCollector->collect($u), $urls);
    }

    /**
     * @return list<string>
     */
    private static function sourceTypes(AuditCheck $check): array
    {
        $types = [];
        foreach ($check->sources ?? [] as $source) {
            $type = is_array($source) ? ($source['type'] ?? null) : null;
            if (is_string($type) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
