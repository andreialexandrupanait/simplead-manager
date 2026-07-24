<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditFixKind;
use App\Models\AuditCard;

/**
 * Faza D4 (skeleton): turns a validated recommendation into a structured, connector-
 * ready set of proposed changes — WITHOUT applying anything. The auditor's per-URL
 * table (the recommended-value column) becomes the proposed changes; the fix kind is
 * inferred from the card's title. Real application through the connector is wired in
 * a follow-up (needs the connector endpoints + a WP test host).
 */
final class AuditFixProposer
{
    /**
     * @return array{
     *     kind: AuditFixKind,
     *     applicable: bool,
     *     changes: list<array{url: string, current: string, proposed: string}>
     * }
     */
    public function propose(AuditCard $card): array
    {
        $kind = self::inferKind((string) $card->title);
        $rows = is_array($card->payload['table']['rows'] ?? null) ? $card->payload['table']['rows'] : [];

        $changes = [];
        foreach ($rows as $row) {
            $normalized = self::normalizeRow($row);
            if ($normalized === null) {
                continue;
            }
            // A proposal needs a recommended value to apply; skip rows the auditor left blank.
            if ($normalized['proposed'] === '') {
                continue;
            }
            $changes[] = $normalized;
        }

        return [
            'kind' => $kind,
            'applicable' => $kind->isConnectorApplicable() && $changes !== [],
            'changes' => $changes,
        ];
    }

    /** Infer the fix kind from the recommendation title (best-effort, skeleton). */
    public static function inferKind(string $title): AuditFixKind
    {
        $t = mb_strtolower($title);

        return match (true) {
            str_contains($t, 'redirect') || str_contains($t, '301') => AuditFixKind::Redirect,
            str_contains($t, 'alt') => AuditFixKind::ImageAlt,
            str_contains($t, 'descriere') || str_contains($t, 'description') || str_contains($t, 'meta desc') => AuditFixKind::MetaDescription,
            str_contains($t, 'titl') || str_contains($t, 'title') => AuditFixKind::MetaTitle,
            default => AuditFixKind::Content,
        };
    }

    /**
     * @return array{url: string, current: string, proposed: string}|null
     */
    private static function normalizeRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        // Editor rows are {url, current, recommended}; be tolerant of positional rows too.
        $url = $row['url'] ?? ($row[0] ?? null);
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $current = $row['current'] ?? ($row[1] ?? '');
        $proposed = $row['recommended'] ?? ($row['proposed'] ?? ($row[2] ?? ''));

        return [
            'url' => trim($url),
            'current' => is_scalar($current) ? (string) $current : '',
            'proposed' => is_scalar($proposed) ? trim((string) $proposed) : '',
        ];
    }
}
