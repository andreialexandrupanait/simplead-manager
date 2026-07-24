<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\CheckState;

/**
 * The v2 validation-editor presentation logic — pure, DB/UI-free. Port of
 * src/lib/methodology/v2-editor.ts.
 *
 * Holds: grouping checks by subsection, the per-section "set/remaining" counters
 * (no scores — v2 has none), the evidence summary from a check result's evidence
 * (figures + first affected URLs), the recommendation table prefill, the short
 * source label from a check's sources, and the finding ordering/navigation.
 */
final class AuditEditorPresenter
{
    /** How many affected URLs the expander shows before "+N more". */
    public const EVIDENCE_URL_PREVIEW = 5;

    /** Cap on the rows prefilled into the recommendation table. */
    public const TABLE_PREFILL_MAX = 100;

    /** How many sources appear in the short label before "+n". */
    public const SOURCE_PREVIEW = 2;

    /**
     * Card ordering: unvalidated drafts first (they need the auditor), then the
     * validated ones, then the rejected. Port of VALIDATION_SORT_ORDER.
     */
    public const VALIDATION_SORT_ORDER = [
        'DRAFT_AI' => 0,
        'APROBAT' => 1,
        'EDITAT' => 1,
        'RESPINS' => 2,
    ];

    /** Keys of an affected row that are NOT "current value". */
    private const NON_VALUE_KEYS = ['url', 'filter'];

    /**
     * Group a section's checks by subsection, preserving input order (seed
     * sort_order). Checks without a subsection (sections 03–06) land in a single
     * group with id null. Port of groupBySubsection.
     *
     * @param  iterable<array<string, mixed>>  $checks  each with subsection_id, subsection_name
     * @return list<array{id: string|null, name: string|null, checks: list<array<string, mixed>>}>
     */
    public static function groupBySubsection(iterable $checks): array
    {
        $groups = [];
        $indexById = [];
        foreach ($checks as $check) {
            $id = self::stringOrNull($check['subsection_id'] ?? null);
            if (! array_key_exists($id ?? '', $indexById)) {
                $groups[] = ['id' => $id, 'name' => self::stringOrNull($check['subsection_name'] ?? null), 'checks' => []];
                $indexById[$id ?? ''] = array_key_last($groups);
            }
            $groups[$indexById[$id ?? '']]['checks'][] = $check;
        }

        return $groups;
    }

    /**
     * Count set / remaining checks (no scores). Port of sectionCounter.
     *
     * @param  iterable<CheckState|string|null>  $states
     * @return array{set: int, remaining: int, total: int}
     */
    public static function sectionCounter(iterable $states): array
    {
        $set = 0;
        $total = 0;
        foreach ($states as $state) {
            $total++;
            if ($state !== null) {
                $set++;
            }
        }

        return ['set' => $set, 'remaining' => $total - $set, 'total' => $total];
    }

    /**
     * Summarize a check result's evidence for the editor expander: figures +
     * first affected URLs + note + NU SE APLICĂ reason. Tolerant of any JSON
     * shape (fetch/manual evidence differs from SF). Port of summarizeEvidence.
     *
     * @return array{total: int|null, urls: list<string>, more: int, truncated: bool, note: string|null, reason: string|null, hasEvidence: bool}
     */
    public static function summarizeEvidence(mixed $evidence): array
    {
        $empty = ['total' => null, 'urls' => [], 'more' => 0, 'truncated' => false, 'note' => null, 'reason' => null, 'hasEvidence' => false];
        $ev = self::asRecord($evidence);
        if ($ev === null) {
            return $empty;
        }

        $urls = [];
        foreach (self::affectedRows($ev) as $row) {
            $url = $row['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        $total = is_int($ev['totalAfectate'] ?? null)
            ? $ev['totalAfectate']
            : ($urls !== [] ? count($urls) : null);
        $preview = array_slice($urls, 0, self::EVIDENCE_URL_PREVIEW);
        $more = max(0, ($total ?? count($urls)) - count($preview));

        $note = $ev['note'] ?? null;
        $reason = $ev['reason'] ?? null;

        return [
            'total' => $total,
            'urls' => $preview,
            'more' => $more,
            'truncated' => ($ev['truncated'] ?? null) === true,
            'note' => is_string($note) && $note !== '' ? $note : null,
            'reason' => is_string($reason) && $reason !== '' ? $reason : null,
            'hasEvidence' => $ev !== [],
        ];
    }

    /**
     * Build the recommendation table's initial [URL, current value, ''] rows from
     * evidence.affected. "Current value" = the row's extra fields (title, length…)
     * or, failing that, the filter that matched it. The recommended value stays
     * empty (the auditor writes it). Port of tableRowsFromEvidence.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function tableRowsFromEvidence(mixed $evidence, int $max = self::TABLE_PREFILL_MAX): array
    {
        $ev = self::asRecord($evidence);
        if ($ev === null) {
            return [];
        }
        $rows = [];
        foreach (self::affectedRows($ev) as $row) {
            if (count($rows) >= $max) {
                break;
            }
            $url = $row['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $extras = [];
            foreach ($row as $k => $v) {
                if (in_array($k, self::NON_VALUE_KEYS, true) || $v === null || $v === '') {
                    continue;
                }
                $extras[] = $k.': '.(is_string($v) ? $v : (string) json_encode($v));
            }
            $filter = $row['filter'] ?? null;
            $current = $extras !== [] ? implode(' · ', $extras) : (is_string($filter) ? $filter : '');
            $rows[] = [$url, $current, ''];
        }

        return $rows;
    }

    /**
     * The short source label for a check's sources row in the state panel (the
     * exact source, abbreviated). Max SOURCE_PREVIEW sources + "+n". Port of
     * shortSource.
     */
    public static function shortSource(mixed $sourcesJson): string
    {
        if (! is_array($sourcesJson)) {
            return '';
        }
        $sources = [];
        foreach ($sourcesJson as $s) {
            $record = self::asRecord($s);
            if ($record !== null) {
                $sources[] = $record;
            }
        }
        if ($sources === []) {
            return '';
        }
        $parts = array_map(self::shortOneSource(...), array_slice($sources, 0, self::SOURCE_PREVIEW));
        $rest = count($sources) - self::SOURCE_PREVIEW;

        return $rest > 0 ? implode(' · ', $parts).' +'.$rest : implode(' · ', $parts);
    }

    /**
     * Stable comparator for recommendation cards (unvalidated → top). Port of
     * compareFindingsForValidation.
     *
     * @param  array{validation: string, sort_order: int}  $a
     * @param  array{validation: string, sort_order: int}  $b
     */
    public static function compareFindingsForValidation(array $a, array $b): int
    {
        $rank = (self::VALIDATION_SORT_ORDER[$a['validation']] ?? 3) <=> (self::VALIDATION_SORT_ORDER[$b['validation']] ?? 3);

        return $rank !== 0 ? $rank : $a['sort_order'] <=> $b['sort_order'];
    }

    /**
     * A sorted copy (unvalidated first) — does not mutate the input. Port of
     * sortFindingsForValidation.
     *
     * @param  list<array{validation: string, sort_order: int}>  $findings
     * @return list<array{validation: string, sort_order: int}>
     */
    public static function sortFindingsForValidation(array $findings): array
    {
        usort($findings, self::compareFindingsForValidation(...));

        return $findings;
    }

    /**
     * How many unvalidated (DRAFT_AI) findings a set has. Port of draftCount.
     *
     * @param  iterable<array{validation: string}>  $findings
     */
    public static function draftCount(iterable $findings): int
    {
        $n = 0;
        foreach ($findings as $f) {
            if ($f['validation'] === 'DRAFT_AI') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The next section (cyclically, after the active one) that still has findings
     * to review. If only the active section has drafts, returns it; if none do,
     * returns null. Port of nextSectionWithDrafts.
     *
     * @param  list<array{id: string, draftCount: int}>  $sections
     */
    public static function nextSectionWithDrafts(array $sections, string $activeId): ?string
    {
        $n = count($sections);
        if ($n === 0) {
            return null;
        }
        $from = 0;
        foreach ($sections as $i => $section) {
            if ($section['id'] === $activeId) {
                $from = $i;
                break;
            }
        }
        for ($step = 1; $step <= $n; $step++) {
            $section = $sections[($from + $step) % $n];
            if ($section['draftCount'] > 0) {
                return $section['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function shortOneSource(array $source): string
    {
        $type = is_string($source['type'] ?? null) ? $source['type'] : '';
        $note = is_string($source['note'] ?? null) ? $source['note'] : '';

        switch ($type) {
            case 'sf_export':
                $tab = is_string($source['tab'] ?? null) ? $source['tab'] : '?';
                $filters = [];
                if (is_array($source['filters'] ?? null)) {
                    foreach ($source['filters'] as $f) {
                        if (is_string($f)) {
                            $filters[] = $f;
                        }
                    }
                }

                return $filters !== [] ? "SF «{$tab}»: ".implode(', ', $filters) : "SF «{$tab}»";
            case 'sf_report':
                return 'SF raport '.(is_string($source['report'] ?? null) ? $source['report'] : '?');
            case 'sf_bulk_export':
                return 'SF bulk '.(is_string($source['export'] ?? null) ? $source['export'] : '?');
            case 'fetch':
                return $note !== '' ? "fetch — {$note}" : 'fetch';
            case 'manual':
                return $note !== '' ? "manual — {$note}" : 'manual';
            case 'gsc':
            case 'ga4':
            case 'bing':
            case 'psi':
            case 'web':
                return $note !== '' ? strtoupper($type)." — {$note}" : strtoupper($type);
            default:
                return $type !== '' ? $type : '?';
        }
    }

    /**
     * The `affected` rows of evidence, normalized to records.
     *
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private static function affectedRows(array $evidence): array
    {
        if (! is_array($evidence['affected'] ?? null)) {
            return [];
        }
        $rows = [];
        foreach ($evidence['affected'] as $row) {
            $record = self::asRecord($row);
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null the value as an associative array, or null when it is not one
     */
    private static function asRecord(mixed $value): ?array
    {
        return is_array($value) && ! array_is_list($value) ? $value : ($value === [] ? [] : null);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
