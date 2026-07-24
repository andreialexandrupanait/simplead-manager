<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Enums\AuditTeam;
use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Services\Audit\AuditEditorMutations;
use App\Services\Audit\AuditEditorPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Faza D: the v2 validation editor — the state panel (set EXISTĂ/NU EXISTĂ/NU SE
 * APLICĂ per check, grouped by subsection) plus the recommendation cards of the
 * active section (approve/reject drafts, create/edit cards on gaps). No scores.
 * Port of ValidationEditorV2 + v2-mutations, on the flattened schema.
 */
class AuditEditor extends Component
{
    public Audit $audit;

    /** The active section_key. */
    public string $activeSection = '';

    // NU SE APLICĂ reason prompt.
    public ?string $reasonForKey = null;

    public string $reasonText = '';

    // Recommendation card form.
    public bool $showCardForm = false;

    public ?int $editingCardId = null;

    public string $cardTitle = '';

    public string $cardDiagnostic = '';

    public string $cardImpact = 'mediu';

    public string $cardEffort = 'mediu';

    public ?string $cardTeam = null;

    /** @var array<int, string> selected gap check keys */
    public array $cardGaps = [];

    /** @var list<array{url: string, current: string, recommended: string}> */
    public array $cardTableRows = [];

    public bool $cardEvidenceConfirmed = false;

    public function mount(Audit $audit): void
    {
        $this->audit = $audit;
        $this->activeSection = $this->firstSectionToCheck();
    }

    public function readOnly(): bool
    {
        return in_array($this->audit->status, [AuditStatus::Validat, AuditStatus::Publicat], true);
    }

    // -- state panel ---------------------------------------------------------

    public function setState(string $checkKey, string $state): void
    {
        $this->guardWritable();
        $enum = CheckState::tryFrom($state);
        if ($enum === null) {
            return;
        }
        if ($enum === CheckState::NuSeAplica) {
            // Collect a reason first.
            $this->reasonForKey = $checkKey;
            $this->reasonText = (string) ($this->resultFor($checkKey)['evidence']['reason'] ?? '');

            return;
        }
        $this->apply($checkKey, $enum, null);
    }

    public function confirmNuSeAplica(): void
    {
        $this->guardWritable();
        if ($this->reasonForKey === null) {
            return;
        }
        $this->apply($this->reasonForKey, CheckState::NuSeAplica, $this->reasonText);
        $this->reasonForKey = null;
        $this->reasonText = '';
    }

    public function cancelReason(): void
    {
        $this->reasonForKey = null;
        $this->reasonText = '';
    }

    private function apply(string $checkKey, CheckState $state, ?string $reason): void
    {
        $error = app(AuditEditorMutations::class)->setCheckState($this->audit->fresh(), $checkKey, $state, $reason);
        $this->afterMutation($error, 'Stare actualizată.');
    }

    // -- cards ---------------------------------------------------------------

    public function approveCard(int $cardId): void
    {
        $this->guardWritable();
        $error = app(AuditEditorMutations::class)->setValidation($this->audit->fresh(), $cardId, 'APROBAT');
        $this->afterMutation($error, 'Recomandare aprobată.');
    }

    public function rejectCard(int $cardId): void
    {
        $this->guardWritable();
        $error = app(AuditEditorMutations::class)->setValidation($this->audit->fresh(), $cardId, 'RESPINS');
        $this->afterMutation($error, 'Recomandare respinsă.');
    }

    public function newCard(?string $checkKey = null): void
    {
        $this->guardWritable();
        $this->resetCardForm();
        $this->showCardForm = true;
        if ($checkKey !== null) {
            $this->cardGaps = [$checkKey];
            $this->prefillTableFrom($checkKey);
        }
    }

    public function editCard(int $cardId): void
    {
        $this->guardWritable();
        $card = AuditCard::query()->where('id', $cardId)->where('audit_id', $this->audit->id)->first();
        if ($card === null) {
            return;
        }
        $this->editingCardId = $card->id;
        $this->cardTitle = (string) $card->title;
        $this->cardDiagnostic = (string) $card->recommendation;
        $this->cardImpact = (string) ($card->impact ?? 'mediu');
        $this->cardEffort = (string) ($card->effort ?? 'mediu');
        $this->cardTeam = is_string($card->team) ? $card->team : null;
        $this->cardGaps = array_values(array_map('strval', is_array($card->check_ids) ? $card->check_ids : []));
        $rows = is_array($card->payload['table']['rows'] ?? null) ? $card->payload['table']['rows'] : [];
        $this->cardTableRows = $this->normalizeRows($rows);
        $this->cardEvidenceConfirmed = false;
        $this->showCardForm = true;
    }

    public function addTableRow(): void
    {
        $this->cardTableRows[] = ['url' => '', 'current' => '', 'recommended' => ''];
    }

    public function removeTableRow(int $index): void
    {
        unset($this->cardTableRows[$index]);
        $this->cardTableRows = array_values($this->cardTableRows);
    }

    public function saveCard(): void
    {
        $this->guardWritable();
        $this->validate([
            'cardTitle' => 'required|string|max:255',
            'cardImpact' => 'required|in:mare,mediu,mic',
            'cardEffort' => 'nullable|in:mare,mediu,mic',
            'cardGaps' => 'required|array|min:1',
        ]);

        $rows = array_values(array_filter(
            $this->cardTableRows,
            static fn (array $r): bool => trim($r['url']) !== '',
        ));
        $payload = $rows !== [] ? ['table' => ['columns' => ['URL', 'Valoare actuală', 'Recomandare'], 'rows' => $rows]] : [];

        $result = app(AuditEditorMutations::class)->upsertRecommendation($this->audit->fresh(), $this->editingCardId, [
            'title' => $this->cardTitle,
            'team' => $this->cardTeam,
            'impact' => $this->cardImpact,
            'effort' => $this->cardEffort,
            'diagnostic' => $this->cardDiagnostic !== '' ? $this->cardDiagnostic : null,
            'checkIds' => array_values($this->cardGaps),
            'payload' => $payload,
            'evidenceConfirmed' => $this->cardEvidenceConfirmed,
        ]);

        if (isset($result['error'])) {
            $this->dispatch('notify', type: 'error', message: $result['error']);

            return;
        }
        $this->resetCardForm();
        $this->audit->refresh();
        $this->clearComputed();
        $this->dispatch('notify', type: 'success', message: 'Recomandare salvată.');
    }

    public function cancelCard(): void
    {
        $this->resetCardForm();
    }

    private function prefillTableFrom(string $checkKey): void
    {
        $evidence = $this->resultFor($checkKey)['evidence'];
        $this->cardTableRows = array_map(
            static fn (array $r): array => ['url' => $r[0], 'current' => $r[1], 'recommended' => $r[2]],
            AuditEditorPresenter::tableRowsFromEvidence($evidence),
        );
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{url: string, current: string, recommended: string}>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $out[] = [
                'url' => (string) ($r['url'] ?? ''),
                'current' => (string) ($r['current'] ?? ''),
                'recommended' => (string) ($r['recommended'] ?? ''),
            ];
        }

        return $out;
    }

    private function resetCardForm(): void
    {
        $this->showCardForm = false;
        $this->editingCardId = null;
        $this->cardTitle = '';
        $this->cardDiagnostic = '';
        $this->cardImpact = 'mediu';
        $this->cardEffort = 'mediu';
        $this->cardTeam = null;
        $this->cardGaps = [];
        $this->cardTableRows = [];
        $this->cardEvidenceConfirmed = false;
    }

    // -- data ----------------------------------------------------------------

    /**
     * @return list<array{key: string, nr: string, name: string}>
     */
    #[Computed]
    public function sections(): array
    {
        return AuditCheck::query()
            ->select('section_key', 'section_nr', 'section_name')
            ->selectRaw('MIN(sort_order) as ord')
            ->groupBy('section_key', 'section_nr', 'section_name')
            ->orderByRaw('MIN(sort_order)')
            ->get()
            ->map(static fn (AuditCheck $c): array => [
                'key' => (string) $c->section_key,
                'nr' => (string) $c->section_nr,
                'name' => (string) $c->section_name,
            ])
            ->all();
    }

    /**
     * Per-section set/remaining/total counters (left nav), keyed by section_key.
     *
     * @return array<string, array{set: int, remaining: int, total: int}>
     */
    #[Computed]
    public function sectionCounts(): array
    {
        $stateByCheckId = AuditCheckResult::query()
            ->where('audit_id', $this->audit->id)
            ->pluck('state', 'audit_check_id');

        $bySection = [];
        foreach (AuditCheck::query()->orderBy('sort_order')->get(['id', 'section_key']) as $c) {
            $bySection[(string) $c->section_key][] = $stateByCheckId[$c->id] ?? null;
        }

        return array_map(
            static fn (array $states): array => AuditEditorPresenter::sectionCounter($states),
            $bySection,
        );
    }

    /**
     * @return array<string, array{key: string, section: string}>
     */
    private function keyMeta(): array
    {
        return AuditCheck::query()
            ->get(['key', 'section_key'])
            ->mapWithKeys(static fn (AuditCheck $c): array => [$c->key => ['key' => $c->key, 'section' => (string) $c->section_key]])
            ->all();
    }

    /**
     * The active section's checks, grouped by subsection, with state + evidence.
     *
     * @return list<array{id: string|null, name: string|null, checks: list<array<string, mixed>>}>
     */
    #[Computed]
    public function subsectionGroups(): array
    {
        $results = $this->resultsForSection($this->activeSection);
        $rows = AuditCheck::query()
            ->where('section_key', $this->activeSection)
            ->orderBy('sort_order')
            ->get()
            ->map(function (AuditCheck $c) use ($results): array {
                $result = $results[$c->id] ?? null;
                $evidence = is_array($result['evidence'] ?? null) ? $result['evidence'] : [];

                return [
                    'key' => (string) $c->key,
                    'question' => (string) $c->question,
                    'subsection_id' => $c->subsection_id,
                    'subsection_name' => $c->subsection_name,
                    'state' => $result['state'] ?? null,
                    'evidence' => $evidence,
                    'sourceLabel' => AuditEditorPresenter::shortSource($c->sources),
                    'summary' => AuditEditorPresenter::summarizeEvidence($evidence),
                ];
            })
            ->all();

        return AuditEditorPresenter::groupBySubsection($rows);
    }

    /**
     * The active section's recommendation cards, drafts first.
     *
     * @return list<AuditCard>
     */
    #[Computed]
    public function cards(): array
    {
        $meta = $this->keyMeta();
        $cards = $this->audit->cards()->get()
            ->filter(function (AuditCard $card) use ($meta): bool {
                $first = is_array($card->check_ids) ? ($card->check_ids[0] ?? null) : null;

                return is_string($first) && ($meta[$first]['section'] ?? null) === $this->activeSection;
            })
            ->values();

        $byId = $cards->keyBy('id');
        $rows = $cards->map(static fn (AuditCard $c): array => [
            'id' => $c->id, 'validation' => (string) $c->validation, 'sort_order' => (int) $c->sort_order,
        ])->all();
        usort($rows, AuditEditorPresenter::compareFindingsForValidation(...));

        return array_values(array_filter(array_map(static fn (array $r): ?AuditCard => $byId->get($r['id']), $rows)));
    }

    /**
     * The gap options (NU_EXISTA checks) of the active section for the card form.
     *
     * @return list<array{key: string, question: string}>
     */
    #[Computed]
    public function gapOptions(): array
    {
        $results = $this->resultsForSection($this->activeSection);
        $gaps = [];
        foreach (AuditCheck::query()->where('section_key', $this->activeSection)->orderBy('sort_order')->get() as $c) {
            $state = $results[$c->id]['state'] ?? null;
            if ($state === CheckState::NuExista) {
                $gaps[] = ['key' => (string) $c->key, 'question' => (string) $c->question];
            }
        }

        return $gaps;
    }

    /**
     * @return array<int, array{state: CheckState|null, evidence: array<string, mixed>|null}>
     */
    private function resultsForSection(string $sectionKey): array
    {
        $ids = AuditCheck::query()->where('section_key', $sectionKey)->pluck('id');

        return AuditCheckResult::query()
            ->where('audit_id', $this->audit->id)
            ->whereIn('audit_check_id', $ids)
            ->get(['audit_check_id', 'state', 'evidence'])
            ->mapWithKeys(static fn (AuditCheckResult $r): array => [
                $r->audit_check_id => ['state' => $r->state, 'evidence' => $r->evidence],
            ])
            ->all();
    }

    /**
     * @return array{state: CheckState|null, evidence: array<string, mixed>}
     */
    private function resultFor(string $checkKey): array
    {
        $checkId = AuditCheck::query()->where('key', $checkKey)->value('id');
        $result = AuditCheckResult::query()
            ->where('audit_id', $this->audit->id)
            ->where('audit_check_id', $checkId)
            ->first(['state', 'evidence']);

        return ['state' => $result?->state, 'evidence' => is_array($result?->evidence) ? $result->evidence : []];
    }

    private function firstSectionToCheck(): string
    {
        $sections = $this->sections();

        return $sections[0]['key'] ?? '';
    }

    public function selectSection(string $sectionKey): void
    {
        $this->activeSection = $sectionKey;
        $this->resetCardForm();
        $this->cancelReason();
        $this->clearComputed();
    }

    private function guardWritable(): void
    {
        abort_if((bool) Auth::user()?->isViewer(), 403, 'Viewers cannot edit an audit.');
        abort_if($this->readOnly(), 403, 'Auditul e validat — doar în citire.');
    }

    private function afterMutation(?string $error, string $success): void
    {
        if ($error !== null) {
            $this->dispatch('notify', type: 'error', message: $error);

            return;
        }
        $this->audit->refresh();
        $this->clearComputed();
        $this->dispatch('notify', type: 'success', message: $success);
    }

    private function clearComputed(): void
    {
        unset($this->sectionCounts, $this->subsectionGroups, $this->cards, $this->gapOptions);
    }

    public function render(): View
    {
        return view('livewire.audit.audit-editor', [
            'teams' => AuditTeam::cases(),
        ])->layout('components.layouts.app', ['title' => __('Validare audit')]);
    }
}
