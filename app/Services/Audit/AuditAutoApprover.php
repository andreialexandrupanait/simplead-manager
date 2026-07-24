<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * The "auto-approvable" classification of v2 recommendations (hands-off validation).
 * Port of src/lib/methodology/auto-approve.ts.
 *
 * INTEGRITY RULE (Andrei's requirement): a recommendation auto-approves ONLY when
 *   1. it is NOT flagged "de verificat" (needsVerification === false), AND
 *   2. it covers exclusively checks whose sources are ALL deterministic.
 *
 * AI / manual judgement (manual/web/ai/gsc/ga4/bing sources) NEVER auto-approves —
 * those cards stay DRAFT_AI and notify the auditor. No uncertain claim reaches the
 * report without a human decision.
 */
final class AuditAutoApprover
{
    /**
     * The DETERMINISTIC source types: machine collection, no judgement.
     * (Screaming Frog, direct fetch, PageSpeed Insights.)
     */
    public const DETERMINISTIC_SOURCE_TYPES = ['sf_export', 'sf_report', 'sf_bulk_export', 'fetch', 'psi'];

    /**
     * A check is deterministic if it has at least one source and ALL of its sources
     * (from AuditCheck.sources, a list of {type} objects) are deterministic. No
     * sources, or any judgement source (manual/web/ai/gsc/ga4/bing) → false.
     */
    public static function isDeterministicCheck(mixed $sources): bool
    {
        if (! is_array($sources) || $sources === []) {
            return false;
        }
        foreach ($sources as $s) {
            $type = is_array($s) ? ($s['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::DETERMINISTIC_SOURCE_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The set of deterministic check keys, from a list of checks with key + sources.
     * Returned as a lookup map (key => true) for O(1) membership.
     *
     * @param  iterable<array{key: string, sources: mixed}>  $checks
     * @return array<string, true>
     */
    public static function deterministicKeysOf(iterable $checks): array
    {
        $keys = [];
        foreach ($checks as $c) {
            if (self::isDeterministicCheck($c['sources'] ?? null)) {
                $keys[(string) $c['key']] = true;
            }
        }

        return $keys;
    }

    /**
     * Whether a recommendation can auto-approve: needsVerification=false AND it
     * covers at least one check, and ALL covered checks (checkIds, keys like
     * "2.7.1") are in the module's deterministic set.
     *
     * @param  array{needsVerification: bool, checkIds: array<array-key, string>}  $finding
     * @param  array<string, true>  $deterministicKeys
     */
    public static function isAutoApprovable(array $finding, array $deterministicKeys): bool
    {
        if ($finding['needsVerification']) {
            return false;
        }
        if ($finding['checkIds'] === []) {
            return false;
        }
        foreach ($finding['checkIds'] as $key) {
            if (! isset($deterministicKeys[$key])) {
                return false;
            }
        }

        return true;
    }
}
