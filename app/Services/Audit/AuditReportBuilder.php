<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;

/**
 * Faza D6: assembles the public report's structured data from an audit — v2, so
 * NO scores. The report shows only the human-validated recommendations
 * (APROBAT/EDITAT), grouped by section, plus the single allowed aggregation
 * ("X of Y implemented"). DRAFT_AI and RESPINS never reach the report.
 */
final class AuditReportBuilder
{
    /** Validation states that appear in the report. */
    private const PUBLISHED = ['APROBAT', 'EDITAT'];

    /**
     * @param  array<string, bool>|null  $implementedOverride  recId → implemented, from a prior report's client toggles
     * @return array{
     *     client: array{name: string, url: string, date: string},
     *     counter: array{implemented: int, total: int},
     *     sections: list<array{nr: string, name: string, cards: list<array<string, mixed>>}>
     * }
     */
    public function build(Audit $audit, ?array $implementedOverride = null): array
    {
        $sectionByKey = $this->sectionByCheckKey();

        $cards = $audit->cards()->whereIn('validation', self::PUBLISHED)->orderBy('sort_order')->get();

        /** @var array<string, array{nr: string, name: string, cards: list<array<string, mixed>>}> $bySection */
        $bySection = [];
        foreach ($cards as $card) {
            $firstKey = is_array($card->check_ids) ? (string) ($card->check_ids[0] ?? '') : '';
            $section = $sectionByKey[$firstKey] ?? ['key' => 'misc', 'nr' => '99', 'name' => 'Diverse'];
            $sectionKey = $section['key'];
            $bySection[$sectionKey] ??= ['nr' => $section['nr'], 'name' => $section['name'], 'cards' => []];
            $bySection[$sectionKey]['cards'][] = $this->cardData($card, $implementedOverride);
        }

        uasort($bySection, static fn (array $a, array $b): int => strcmp($a['nr'], $b['nr']));

        $target = $audit->target();

        return [
            'client' => [
                'name' => $target !== null ? (string) $target->name : (string) $audit->url,
                'url' => (string) $audit->url,
                'date' => ($audit->updated_at ?? $audit->created_at)?->format('d.m.Y') ?? '',
            ],
            'counter' => AuditImplementationCounter::forAudit($audit),
            'sections' => array_values($bySection),
        ];
    }

    /**
     * @param  array<string, bool>|null  $implementedOverride
     * @return array<string, mixed>
     */
    private function cardData(AuditCard $card, ?array $implementedOverride): array
    {
        $recId = (string) $card->id;
        $implemented = $implementedOverride !== null && array_key_exists($recId, $implementedOverride)
            ? $implementedOverride[$recId]
            : $card->implementation === 'IMPLEMENTAT';

        return [
            'id' => $recId,
            'title' => (string) $card->title,
            'team' => (string) $card->team,
            'impact' => (string) $card->impact,
            'effort' => (string) $card->effort,
            'recommendation' => (string) $card->recommendation,
            'evidence_text' => (string) $card->evidence_text,
            'table' => is_array($card->payload['table'] ?? null) ? $card->payload['table'] : null,
            'implemented' => $implemented,
        ];
    }

    /**
     * check key → its section (key/nr/name).
     *
     * @return array<string, array{key: string, nr: string, name: string}>
     */
    private function sectionByCheckKey(): array
    {
        return AuditCheck::query()
            ->get(['key', 'section_key', 'section_nr', 'section_name'])
            ->mapWithKeys(static fn (AuditCheck $c): array => [
                (string) $c->key => ['key' => (string) $c->section_key, 'nr' => (string) $c->section_nr, 'name' => (string) $c->section_name],
            ])
            ->all();
    }
}
