<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditStatus;
use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;

/**
 * The v2 validation-editor mutations — DB logic behind the Livewire editor. Port
 * of src/lib/methodology/v2-mutations.ts, flattened to our schema (no module
 * instances: checks are global, results/cards hang off the audit).
 *
 * Every mutation requires an editable status; the first editor write advances
 * DRAFT → IN_VALIDARE (as in v1). No scores — v2 has none.
 *
 * Each method returns an error string (Romanian, surfaced in the UI) or null on
 * success; upsertRecommendation returns ['cardId' => int] on success.
 */
final class AuditEditorMutations
{
    private const EDITABLE = [AuditStatus::Colectare, AuditStatus::Draft, AuditStatus::InValidare];

    /** impact → a neutral severity, only for data consistency (v2 hides severity). */
    private const SEVERITY_FROM_IMPACT = ['mare' => 'RIDICAT', 'mediu' => 'MEDIU', 'mic' => 'SCAZUT'];

    /**
     * Set EXISTA / NU_EXISTA / NU_SE_APLICA on one check. The NU SE APLICĂ reason
     * is stored in evidence.reason (cleared on any other state). Collected evidence
     * is kept, marked state_set_by="manual".
     */
    public function setCheckState(Audit $audit, string $checkKey, CheckState $state, ?string $reason = null): ?string
    {
        if (! $this->isEditable($audit)) {
            return 'Auditul nu mai e editabil (doar în colectare, draft sau validare).';
        }
        $check = AuditCheck::query()->where('key', $checkKey)->first(['id']);
        if ($check === null) {
            return "Verificarea {$checkKey} nu există.";
        }

        $existing = AuditCheckResult::query()
            ->where('audit_id', $audit->id)
            ->where('audit_check_id', $check->id)
            ->first(['id', 'evidence']);

        $evidence = is_array($existing?->evidence) ? $existing->evidence : [];
        if ($state === CheckState::NuSeAplica) {
            $evidence['reason'] = $reason ?? '';
        } else {
            unset($evidence['reason']);
        }

        AuditCheckResult::query()->updateOrCreate(
            ['audit_id' => $audit->id, 'audit_check_id' => $check->id],
            ['state' => $state, 'evidence' => $evidence, 'state_set_by' => 'manual', 'collected_at' => now()],
        );

        $this->advanceStatus($audit);

        return null;
    }

    /**
     * Create or edit a recommendation card. Report rule ("no card without a gap"):
     * every covered check key must have state NU_EXISTA for this audit. Sets
     * validation=EDITAT, auto_approved=false (human edit takes ownership).
     *
     * @param  array{title: string, team?: string|null, impact: string, effort?: string|null, diagnostic?: string|null, checkIds: array<array-key, string>, payload?: array<string, mixed>, evidenceConfirmed?: bool}  $data
     * @return array{error: string}|array{cardId: int}
     */
    public function upsertRecommendation(Audit $audit, ?int $cardId, array $data): array
    {
        if (! $this->isEditable($audit)) {
            return ['error' => 'Auditul nu mai e editabil (doar în colectare, draft sau validare).'];
        }

        $checkIds = array_values($data['checkIds']);
        if ($checkIds === []) {
            return ['error' => 'Un card trebuie să acopere cel puțin o verificare NU EXISTĂ.'];
        }

        $error = $this->assertAllGaps($audit, $checkIds);
        if ($error !== null) {
            return ['error' => $error];
        }

        $impact = $data['impact'];
        $attributes = [
            'title' => $data['title'],
            'team' => $data['team'] ?? null,
            'impact' => $impact,
            'effort' => $data['effort'] ?? null,
            'recommendation' => $data['diagnostic'] ?? null,
            'evidence_text' => 'Acoperă verificările: '.implode(', ', $checkIds).' (stare NU EXISTĂ).',
            'check_ids' => $checkIds,
            'payload' => $this->normalizePayload($data['payload'] ?? []),
            'validation' => 'EDITAT',
            'auto_approved' => false,
        ];

        if ($cardId !== null) {
            $card = AuditCard::query()->where('id', $cardId)->where('audit_id', $audit->id)->first();
            if ($card === null) {
                return ['error' => 'Recomandarea nu aparține acestui audit.'];
            }
            if ($card->validation === 'RESPINS') {
                return ['error' => 'O recomandare respinsă nu se mai editează.'];
            }
            $confirmed = ($data['evidenceConfirmed'] ?? false) === true;
            if ($card->needs_verification && ! $confirmed) {
                return ['error' => 'Bifează „Dovadă confirmată" pentru a valida o recomandare marcată „de verificat".'];
            }
            $card->update([...$attributes, 'needs_verification' => $confirmed ? false : $card->needs_verification]);
            $this->advanceStatus($audit);

            return ['cardId' => $card->id];
        }

        $nextOrder = (int) (AuditCard::query()->where('audit_id', $audit->id)->max('sort_order') ?? -1) + 1;
        $card = AuditCard::query()->create([
            'audit_id' => $audit->id,
            ...$attributes,
            'implementation' => 'NEIMPLEMENTAT',
            'needs_verification' => false,
            'sort_order' => $nextOrder,
        ]);
        $this->advanceStatus($audit);

        return ['cardId' => $card->id];
    }

    /**
     * The decision on an AI draft: APROBAT / RESPINS. Only DRAFT_AI cards; an
     * APROBAT is blocked while the card is flagged "de verificat".
     */
    public function setValidation(Audit $audit, int $cardId, string $validation): ?string
    {
        if (! in_array($validation, ['APROBAT', 'RESPINS'], true)) {
            return 'Decizie invalidă.';
        }
        if (! $this->isEditable($audit)) {
            return 'Auditul nu mai e editabil (doar în colectare, draft sau validare).';
        }
        $card = AuditCard::query()->where('id', $cardId)->where('audit_id', $audit->id)->first();
        if ($card === null) {
            return 'Recomandarea nu aparține acestui audit.';
        }
        if ($card->validation !== 'DRAFT_AI') {
            return $validation === 'APROBAT'
                ? 'Doar draft-urile AI se pot aproba direct.'
                : 'Doar draft-urile AI se pot respinge.';
        }
        if ($validation === 'APROBAT' && $card->needs_verification) {
            return 'Recomandarea e marcată „de verificat" — editeaz-o și bifează „Dovadă confirmată" sau respinge-o.';
        }

        $card->update(['validation' => $validation]);
        $this->advanceStatus($audit);

        return null;
    }

    /**
     * The severity derived from impact (data consistency only — never shown in v2).
     */
    public static function severityFromImpact(string $impact): string
    {
        return self::SEVERITY_FROM_IMPACT[$impact] ?? 'MEDIU';
    }

    /**
     * Every covered check key must belong to the methodology and be NU_EXISTA for
     * this audit.
     *
     * @param  list<string>  $checkIds
     */
    private function assertAllGaps(Audit $audit, array $checkIds): ?string
    {
        $idByKey = AuditCheck::query()->whereIn('key', $checkIds)->pluck('id', 'key');
        $stateById = AuditCheckResult::query()
            ->where('audit_id', $audit->id)
            ->whereIn('audit_check_id', $idByKey->values())
            ->pluck('state', 'audit_check_id');

        foreach ($checkIds as $key) {
            $id = $idByKey[$key] ?? null;
            if ($id === null) {
                return "Verificarea {$key} nu există în metodologie.";
            }
            $state = $stateById[$id] ?? null;
            $value = $state instanceof CheckState ? $state->value : $state;
            if ($value !== CheckState::NuExista->value) {
                return "Verificarea {$key} nu are starea NU EXISTĂ — recomandările se creează doar pe goluri.";
            }
        }

        return null;
    }

    /**
     * Normalize a card payload: empty components are dropped entirely.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $out = [];
        $table = $payload['table'] ?? null;
        if (is_array($table) && is_array($table['rows'] ?? null) && $table['rows'] !== []) {
            $out['table'] = $table;
        }
        foreach (['codeBlocks', 'callouts'] as $key) {
            if (is_array($payload[$key] ?? null) && $payload[$key] !== []) {
                $out[$key] = $payload[$key];
            }
        }

        return $out;
    }

    private function isEditable(Audit $audit): bool
    {
        return in_array($audit->status, self::EDITABLE, true);
    }

    /** DRAFT → IN_VALIDARE on the first editor mutation (as in v1). */
    private function advanceStatus(Audit $audit): void
    {
        if ($audit->status === AuditStatus::Draft) {
            $audit->update(['status' => AuditStatus::InValidare]);
        }
    }
}
