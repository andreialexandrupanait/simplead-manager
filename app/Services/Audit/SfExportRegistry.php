<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * The registry of Screaming Frog exports requested by the v2 methodology crawl,
 * plus the file-name resolution that maps a requested CLI label to the file SF
 * actually wrote. Ported VERBATIM from the audit repo
 * (src/lib/evaluation/v2/exports.ts): the label lists, the normalization rules,
 * and the fuzzy numeric-threshold matching are copied 1:1 — see the note on each
 * method. These labels are the contract with SF 24.3; do not "tidy" them.
 */
final class SfExportRegistry
{
    /**
     * The 57 Tab:Filter export tabs requested with --export-tabs.
     *
     * @var list<string>
     */
    public const EXPORT_TABS = [
        'Internal:All',
        'Internal:Images',
        'Response Codes:Blocked by Robots.txt',
        'Response Codes:Redirection (3xx)',
        'Response Codes:Client Error (4xx)',
        'Response Codes:Server Error (5xx)',
        'Response Codes:Internal Redirect Chain',
        'Response Codes:Internal Redirect Loop',
        'Page Titles:Missing',
        'Page Titles:Duplicate',
        'Page Titles:Multiple',
        'Page Titles:Same as H1',
        'Page Titles:Over X Characters',
        'Page Titles:Below X Characters',
        'Meta Description:Missing',
        'Meta Description:Duplicate',
        'Meta Description:Over X Characters',
        'Meta Description:Below X Characters',
        'H1:Missing',
        'H1:Multiple',
        'H1:Duplicate',
        'H2:Duplicate',
        'Images:Missing Alt Text',
        'Images:Missing Alt Attribute',
        'Canonicals:Missing',
        'Canonicals:Canonicalised',
        'Canonicals:Canonical Is Relative',
        'Canonicals:Multiple Conflicting',
        'Pagination:Paginated 2+ Pages',
        'Pagination:Non-Indexable',
        'Directives:Noindex',
        'Security:HTTP URLs',
        'Security:Mixed Content',
        'Security:Missing HSTS Header',
        'Security:Missing Content-Security-Policy Header',
        'Security:Missing X-Content-Type-Options Header',
        'Security:Missing X-Frame-Options Header',
        'Security:Missing Secure Referrer-Policy Header',
        'URL:Uppercase',
        'URL:Underscores',
        'URL:Contains Space',
        'URL:Non ASCII Characters',
        'URL:Parameters',
        'URL:Over X Characters',
        'URL:Repetitive Path',
        'URL:Multiple Slashes',
        'URL:Internal Search',
        'Links:Non-Descriptive Anchor Text In Internal Outlinks',
        'Links:Internal Outlinks With No Anchor Text',
        'Structured Data:Contains Structured Data',
        'Structured Data:Missing',
        'Structured Data:Validation Errors',
        'Structured Data:Validation Warnings',
        'Structured Data:Rich Result Validation Errors',
        'Sitemaps:URLs not in Sitemap',
        'Sitemaps:Orphan URLs',
        'Sitemaps:Non-Indexable URLs in Sitemap',
    ];

    /**
     * The 2 bulk exports requested with --bulk-export.
     *
     * @var list<string>
     */
    public const BULK_EXPORTS = [
        'Links:All Inlinks',
        'Images:Images Missing Alt Text Inlinks',
    ];

    /**
     * The 5 reports requested with --save-report.
     *
     * @var list<string>
     */
    public const SAVE_REPORTS = [
        'Crawl Overview',
        'Redirects:Redirect Chains',
        'Canonicals:Canonical Chains',
        'Canonicals:Non-Indexable Canonicals',
        'Orphan Pages',
    ];

    /**
     * Normalize a label/file name to a comparison key: lowercase, any
     * non-alphanumeric → "_", collapsed underscores.
     * "Response Codes:Blocked by Robots.txt" → "response_codes_blocked_by_robots_txt".
     */
    public static function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\.csv$/', '', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? $name;

        return trim($name, '_');
    }

    /**
     * The "fuzzy" key: segments that are only digits or only "x" become "x".
     * Covers numeric-threshold filters — the CLI lists them with X ("Over X
     * Characters") but the file can come out with the effective threshold
     * ("page_titles_over_60_characters.csv"). Mixed segments ("h1", "4xx") stay
     * untouched so "h1_duplicate" is not confused with "h2_duplicate".
     */
    public static function fuzzyKey(string $name): string
    {
        $segments = explode('_', self::normalizeName($name));
        $segments = array_map(
            static fn (string $seg): string => preg_match('/^(\d+|x)$/', $seg) === 1 ? 'x' : $seg,
            $segments,
        );

        return implode('_', $segments);
    }

    /**
     * Resolve the label to a file in the folder. Order: exact match on the full
     * normalized form → on the part after ":" → fuzzy (digits → x) on both. Bulk
     * exports and reports do not carry the tab prefix in the file name
     * (all_inlinks.csv, crawl_overview.csv) — hence the "after :" candidate.
     *
     * @param  list<string>  $filesInDir
     */
    public static function resolveFileName(string $label, array $filesInDir): ?string
    {
        $byExact = [];
        $byFuzzy = [];
        foreach ($filesInDir as $f) {
            if (preg_match('/\.csv$/i', $f) !== 1) {
                continue;
            }
            $exact = self::normalizeName($f);
            $byExact[$exact] ??= $f;
            $fuzzy = self::fuzzyKey($f);
            $byFuzzy[$fuzzy] ??= $f;
        }

        foreach (self::labelCandidates($label) as $cand) {
            if (isset($byExact[$cand])) {
                return $byExact[$cand];
            }
        }
        foreach (self::labelCandidates($label) as $cand) {
            $fk = self::fuzzyKey($cand);
            if (isset($byFuzzy[$fk])) {
                return $byFuzzy[$fk];
            }
        }

        return null;
    }

    /**
     * The name candidates for a label: the full form and the part after ":",
     * each also in the variant with hyphens REMOVED (not replaced by underscore)
     * — SF 24.3 builds the file name by joining hyphenated words (confirmed on a
     * real crawl: "Missing Content-Security-Policy Header" →
     * security_missing_contentsecuritypolicy_header.csv).
     *
     * @return list<string>
     */
    private static function labelCandidates(string $label): array
    {
        $bases = [$label];
        $colon = strpos($label, ':');
        if ($colon !== false) {
            $bases[] = substr($label, $colon + 1);
        }

        $out = [];
        foreach ($bases as $base) {
            $stripped = preg_replace('/(?<=[a-z0-9])-(?=[a-z0-9])/i', '', $base) ?? $base;
            foreach ([$base, $stripped] as $variant) {
                $norm = self::normalizeName($variant);
                if (! in_array($norm, $out, true)) {
                    $out[] = $norm;
                }
            }
        }

        return $out;
    }
}
