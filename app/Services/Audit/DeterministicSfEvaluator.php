<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\SfExportFile;
use App\DTOs\Audit\SfExports;
use App\DTOs\Audit\V2Eval;
use App\Enums\CheckState;

/**
 * The v2 deterministic evaluators over the Screaming Frog exports. Port of
 * src/lib/evaluation/v2/evaluators.ts (the SF part; PSI + fetch + orchestrator
 * land in D3b).
 *
 * Base semantic (mapare-export-verificari.md): an empty SF filter is POSITIVE
 * evidence. A check with several filters combines them: all empty → EXISTA; any
 * populated → NU_EXISTA, with the affected URLs in evidence (the basis of the
 * per-URL tables in the report), capped at MAX_EVIDENCE_URLS with truncated:true.
 *
 * --skip-empty: an absent file means "empty filter" ONLY for the labels requested
 * at crawl time. Precondition exceptions (Structured Data, Sitemaps) get sentinel
 * files and explicit notes. Checks that can't be proven from SF stay state=null.
 *
 * Constants, filter lists, thresholds and evidence note strings are copied
 * verbatim — do not "translate" them.
 */
final class DeterministicSfEvaluator
{
    /** The cap on the affected-URL list per check. */
    public const MAX_EVIDENCE_URLS = 500;

    /** The standard note about --skip-empty semantics, attached to SF evidence. */
    public const SKIP_EMPTY_NOTE =
        'Filtru SF gol = dovadă pozitivă. Crawl rulat cu --skip-empty: fișierul absent al unui '
        .'export cerut în --export-tabs înseamnă filtru gol (zero URL-uri afectate).';

    private const NOTE_PRECONDITIE_SITEMAPS =
        'Exporturile Sitemaps necesită „Crawl Linked XML Sitemaps” + Crawl Analysis (rulează automat '
        .'în headless pe SF 24.3 cu configurația implicită — validat 12.07.2026). Dacă site-ul nu are '
        .'sitemap, dovada se confirmă manual cu fetch pe /sitemap.xml.';

    private const PAGINATION_TITLE_RE = '/^pagina\s+\d+\s*[-–—]/iu';

    // -----------------------------------------------------------------------
    // Base engine
    // -----------------------------------------------------------------------

    /**
     * Base semantic: combine a check's "fail" filters. All empty → EXISTA; any
     * populated → NU_EXISTA + the affected URLs.
     *
     * @param  list<string>  $failLabels
     * @param  array{infoLabels?: list<string>, notes?: list<string>, indexableOnly?: bool, extra?: callable}  $options
     */
    public static function combineFilters(SfExports $exports, array $failLabels, array $options = []): V2Eval
    {
        $infoLabels = $options['infoLabels'] ?? [];
        $notes = $options['notes'] ?? [];
        $indexableOnly = $options['indexableOnly'] ?? false;
        $extra = $options['extra'] ?? null;

        $affectedAll = [];
        $filters = [];
        $totalRows = 0;

        foreach ($failLabels as $label) {
            $f = $exports->exportOf($label);
            $rows = $indexableOnly ? self::onlyIndexable($f->rows) : $f->rows;
            $summary = self::fileSummary($f);
            if ($indexableOnly && count($rows) !== count($f->rows)) {
                $summary['rowsIndexabile'] = count($rows);
                $summary['notaIndexabilitate'] = 'doar rândurile indexabile pică verificarea';
            }
            $filters[] = $summary;
            $totalRows += count($rows);
            foreach ($rows as $row) {
                $affectedAll[] = array_merge(
                    ['url' => self::rowUrl($row), 'filter' => $label],
                    $extra !== null ? $extra($row) : [],
                );
            }
        }

        $info = array_map(fn (string $l): array => self::fileSummary($exports->exportOf($l)), $infoLabels);
        ['affected' => $affected, 'truncated' => $truncated] = self::capAffected($affectedAll);

        $evidence = [
            'note' => implode(' ', array_merge([self::SKIP_EMPTY_NOTE], $notes)),
            'filters' => $filters,
        ];
        if (count($info) > 0) {
            $evidence['informativ'] = $info;
        }
        $evidence['totalAfectate'] = $totalRows;
        $evidence['affected'] = $affected;
        $evidence['truncated'] = $truncated;

        return new V2Eval($totalRows > 0 ? CheckState::NuExista : CheckState::Exista, $evidence);
    }

    /**
     * Cap the affected list at MAX_EVIDENCE_URLS and flag truncation.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{affected: list<array<string, mixed>>, truncated: bool}
     */
    public static function capAffected(array $rows): array
    {
        if (count($rows) <= self::MAX_EVIDENCE_URLS) {
            return ['affected' => $rows, 'truncated' => false];
        }

        return ['affected' => array_slice($rows, 0, self::MAX_EVIDENCE_URLS), 'truncated' => true];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function fileSummary(SfExportFile $f): array
    {
        $summary = [
            'label' => $f->label,
            'fileName' => $f->fileName,
            'present' => $f->present,
            'rows' => count($f->rows),
        ];
        if ($f->parseTruncated) {
            $summary['parseTruncated'] = true;
        }
        if (! $f->present && $f->requested) {
            $summary['absentAsEmpty'] = true;
        }

        return $summary;
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function rowUrl(array $row): string
    {
        return $row['Address'] ?? $row['Source'] ?? $row['URL'] ?? '';
    }

    /**
     * Keep only indexable rows when the Indexability column exists.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private static function onlyIndexable(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => ! array_key_exists('Indexability', $r) || $r['Indexability'] === 'Indexable',
        ));
    }

    /**
     * @param  array<string, mixed>  $extraEvidence
     */
    private static function evidenceOnly(string $note, array $extraEvidence = []): V2Eval
    {
        return new V2Eval(null, array_merge(['note' => $note], $extraEvidence));
    }

    // -----------------------------------------------------------------------
    // Per-check SF evaluators
    // -----------------------------------------------------------------------

    /** 2.1.3 — URLs with parameters: only the still-indexable (non-canonicalised) ones fail. */
    private static function eval213(SfExports $exports): V2Eval
    {
        $params = $exports->exportOf('URL:Parameters');
        $canonicalised = $exports->exportOf('Canonicals:Canonicalised');
        $indexable = self::onlyIndexable($params->rows);
        ['affected' => $affected, 'truncated' => $truncated] = self::capAffected(array_map(
            static fn (array $r): array => [
                'url' => self::rowUrl($r),
                'filter' => 'URL:Parameters',
                'indexability' => $r['Indexability'] ?? null,
                'canonical' => $r['Canonical Link Element 1'] ?? null,
            ],
            $indexable,
        ));

        return new V2Eval(
            count($indexable) > 0 ? CheckState::NuExista : CheckState::Exista,
            [
                'note' => self::SKIP_EMPTY_NOTE.' Pică doar URL-urile cu parametri rămase indexabile — variantele '
                    .'canonicalizate sunt conforme. Corectitudinea țintei de canonical per tip de parametru '
                    .'se judecă manual pe eșantion.',
                'filters' => [self::fileSummary($params)],
                'informativ' => [self::fileSummary($canonicalised)],
                'totalUrlsCuParametri' => count($params->rows),
                'totalAfectate' => count($indexable),
                'affected' => $affected,
                'truncated' => $truncated,
            ],
        );
    }

    /** 2.2.5 — all indexable pages answer 200 (from Internal:All). */
    private static function eval225(SfExports $exports): V2Eval
    {
        $internal = $exports->exportOf('Internal:All');
        if (! $internal->present) {
            return self::evidenceOnly(
                'Internal:All lipsește din crawl — verificarea nu poate fi evaluată automat (export obligatoriu).',
            );
        }
        $failing = array_values(array_filter(
            $internal->rows,
            static fn (array $r): bool => ($r['Indexability'] ?? '') === 'Indexable' && ($r['Status Code'] ?? '') !== '200',
        ));
        ['affected' => $affected, 'truncated' => $truncated] = self::capAffected(array_map(
            static fn (array $r): array => [
                'url' => self::rowUrl($r),
                'status' => $r['Status Code'] ?? null,
                'statusText' => $r['Status'] ?? null,
            ],
            $failing,
        ));
        $info = array_map(
            fn (string $l): array => self::fileSummary($exports->exportOf($l)),
            ['Response Codes:Redirection (3xx)', 'Response Codes:Client Error (4xx)', 'Response Codes:Server Error (5xx)'],
        );

        return new V2Eval(
            count($failing) > 0 ? CheckState::NuExista : CheckState::Exista,
            [
                'note' => self::SKIP_EMPTY_NOTE.' Evaluat din Internal:All: pagini indexabile cu status ≠ 200.',
                'filters' => [self::fileSummary($internal)],
                'informativ' => $info,
                'totalAfectate' => count($failing),
                'affected' => $affected,
                'truncated' => $truncated,
            ],
        );
    }

    /** 2.2.6 — zero orphan pages (Internal:All HTML 200 with Unique Inlinks = 0 + Orphan Pages report). */
    private static function eval226(SfExports $exports): V2Eval
    {
        $internal = $exports->exportOf('Internal:All');
        if (! $internal->present) {
            return self::evidenceOnly('Internal:All lipsește din crawl — verificarea nu poate fi evaluată automat.');
        }
        $orphans = array_values(array_filter(
            $internal->rows,
            static fn (array $r): bool => str_starts_with($r['Content Type'] ?? '', 'text/html')
                && ($r['Status Code'] ?? '') === '200'
                && ($r['Unique Inlinks'] ?? '') === '0',
        ));
        ['affected' => $affected, 'truncated' => $truncated] = self::capAffected(array_map(
            static fn (array $r): array => [
                'url' => self::rowUrl($r),
                'uniqueInlinks' => 0,
                'indexability' => $r['Indexability'] ?? null,
            ],
            $orphans,
        ));
        $report = $exports->exportOf('Orphan Pages');
        $sitemapOrphans = $exports->exportOf('Sitemaps:Orphan URLs');

        return new V2Eval(
            count($orphans) > 0 ? CheckState::NuExista : CheckState::Exista,
            [
                'note' => self::SKIP_EMPTY_NOTE.' Evaluat din Internal:All (pagini HTML 200 cu Unique Inlinks = 0). '
                    .'Fără GSC + GA4 conectate la crawl, orfanele cunoscute doar de Google nu apar — '
                    .'raportul Orphan Pages și filtrul Sitemaps:Orphan URLs sunt incluse informativ.',
                'filters' => [self::fileSummary($internal)],
                'informativ' => [self::fileSummary($report), self::fileSummary($sitemapOrphans)],
                'totalAfectate' => count($orphans),
                'affected' => $affected,
                'truncated' => $truncated,
            ],
        );
    }

    /** 2.7.5 — page 2+ titles carry the "Pagina X - " prefix (cross Pagination ↔ Internal:All). */
    private static function eval275(SfExports $exports): V2Eval
    {
        $paginated = $exports->exportOf('Pagination:Paginated 2+ Pages');
        if (count($paginated->rows) === 0) {
            return new V2Eval(CheckState::NuSeAplica, [
                'note' => self::SKIP_EMPTY_NOTE.' Nicio pagină de paginare (rel next/prev) detectată la crawl — '
                    .'verificarea prefixului „Pagina X - ” nu se aplică.',
                'filters' => [self::fileSummary($paginated)],
            ]);
        }
        $internal = $exports->exportOf('Internal:All');
        $titleByAddress = [];
        foreach ($internal->rows as $r) {
            $titleByAddress[self::rowUrl($r)] = $r['Title 1'] ?? '';
        }
        $failing = [];
        $unknown = 0;
        foreach ($paginated->rows as $row) {
            $url = self::rowUrl($row);
            if (! array_key_exists($url, $titleByAddress)) {
                $unknown++;

                continue;
            }
            $title = $titleByAddress[$url];
            if (preg_match(self::PAGINATION_TITLE_RE, trim($title)) !== 1) {
                $failing[] = ['url' => $url, 'title' => $title, 'filter' => 'Pagination:Paginated 2+ Pages'];
            }
        }
        ['affected' => $affected, 'truncated' => $truncated] = self::capAffected($failing);

        return new V2Eval(
            count($failing) > 0 ? CheckState::NuExista : CheckState::Exista,
            [
                'note' => self::SKIP_EMPTY_NOTE.' Titlurile paginilor 2+ trebuie să înceapă cu „Pagina X - ” '
                    .'(încrucișare Pagination:Paginated 2+ Pages ↔ Internal:All pe Address).',
                'filters' => [self::fileSummary($paginated), self::fileSummary($internal)],
                'totalPaginate' => count($paginated->rows),
                'faraTitluCunoscut' => $unknown,
                'totalAfectate' => count($failing),
                'affected' => $affected,
                'truncated' => $truncated,
            ],
        );
    }

    /** 2.11.4 — zero structured-data validation errors (with a precondition sentinel). */
    private static function eval2114(SfExports $exports): V2Eval
    {
        $contains = $exports->exportOf('Structured Data:Contains Structured Data');
        $missing = $exports->exportOf('Structured Data:Missing');
        if (! $contains->present && ! $missing->present) {
            return self::evidenceOnly(
                'Niciun export Structured Data prezent — probabil extraction/validation nu au fost active '
                    .'la crawl (precondiție lipsă). Verificarea nu se declară automat; reia crawl-ul cu '
                    .'configurația corectă sau validează manual.',
                ['informativ' => [self::fileSummary($contains), self::fileSummary($missing)]],
            );
        }

        return self::combineFilters(
            $exports,
            ['Structured Data:Validation Errors', 'Structured Data:Rich Result Validation Errors'],
            [
                'infoLabels' => ['Structured Data:Validation Warnings', 'Structured Data:Contains Structured Data'],
                'notes' => ['Avertismentele (Validation Warnings) sunt informative, nu pică verificarea.'],
                'extra' => static fn (array $r): array => [
                    'errors' => $r['Errors'] ?? null,
                    'richResultErrors' => $r['Rich Result Errors'] ?? null,
                ],
            ],
        );
    }

    /** 2.12.1 — page 2+ self-canonical, not to page 1; NU_SE_APLICA without pagination. */
    private static function eval2121(SfExports $exports): V2Eval
    {
        $paginated = $exports->exportOf('Pagination:Paginated 2+ Pages');
        if (count($paginated->rows) === 0) {
            return new V2Eval(CheckState::NuSeAplica, [
                'note' => self::SKIP_EMPTY_NOTE.' Nicio pagină de paginare detectată la crawl — nu se aplică.',
                'filters' => [self::fileSummary($paginated)],
            ]);
        }

        return self::combineFilters($exports, ['Pagination:Non-Indexable'], [
            'infoLabels' => ['Pagination:Paginated 2+ Pages', 'Canonicals:Canonicalised'],
            'notes' => [
                'Paginile de paginare non-indexabile (tipic canonicalizate spre pagina 1) pică verificarea.',
                'Existența duplicatului /page/1 se probează separat (fetch manual pe varianta /page/1).',
            ],
            'extra' => static fn (array $r): array => [
                'indexabilityStatus' => $r['Indexability Status'] ?? null,
                'canonical' => $r['Canonical Link Element 1'] ?? null,
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // The full SF check map
    // -----------------------------------------------------------------------

    /**
     * The automated SF results for the covered checks. Keys missing from the map
     * are left to the other sources (fetch/manual).
     *
     * @return array<string, V2Eval>
     */
    public static function evaluateSfChecks(SfExports $exports): array
    {
        $out = [];
        $internal = $exports->exportOf('Internal:All');

        // --- 2.1 SEO-friendly URLs --------------------------------------------
        $out['2.1.1'] = self::combineFilters(
            $exports,
            ['URL:Uppercase', 'URL:Underscores', 'URL:Contains Space'],
            ['indexableOnly' => true],
        );
        $out['2.1.2'] = self::combineFilters($exports, ['URL:Non ASCII Characters'], ['indexableOnly' => true]);
        $out['2.1.3'] = self::eval213($exports);
        $out['2.1.4'] = self::combineFilters(
            $exports,
            ['URL:Over X Characters', 'URL:Repetitive Path', 'URL:Multiple Slashes'],
            ['indexableOnly' => true],
        );

        // --- 2.2 Crawling & indexability --------------------------------------
        $out['2.2.1'] = self::combineFilters($exports, ['Directives:Noindex'], [
            'notes' => [
                'Lista paginilor „importante” e o decizie manuală — noindex-ul poate fi intenționat '
                    .'pe o parte din URL-urile afectate; auditorul confirmă în editor.',
            ],
            'extra' => static fn (array $r): array => ['metaRobots' => $r['Meta Robots 1'] ?? null],
        ]);
        $out['2.2.2'] = self::combineFilters(
            $exports,
            ['Canonicals:Missing', 'Canonicals:Canonical Is Relative', 'Canonicals:Multiple Conflicting'],
            [
                'indexableOnly' => true,
                'infoLabels' => [
                    'Canonicals:Canonicalised',
                    'Canonicals:Canonical Chains',
                    'Canonicals:Non-Indexable Canonicals',
                ],
                'notes' => [
                    'Canonicals:Canonicalised e informativ: paginile canonicalizate spre alt URL sunt '
                        .'non-indexabile prin definiție și nu încalcă self-canonical-ul paginilor indexabile.',
                ],
                'extra' => static fn (array $r): array => ['canonical' => $r['Canonical Link Element 1'] ?? null],
            ],
        );
        $out['2.2.3'] = self::combineFilters($exports, ['Sitemaps:URLs not in Sitemap'], [
            'notes' => [self::NOTE_PRECONDITIE_SITEMAPS],
            'extra' => static fn (array $r): array => ['indexability' => $r['Indexability'] ?? null],
        ]);
        $out['2.2.4'] = self::combineFilters($exports, ['Response Codes:Blocked by Robots.txt'], [
            'notes' => ['Conținutul integral al regulilor robots.txt e probat separat la 6.1 (fetch /robots.txt).'],
        ]);
        $out['2.2.5'] = self::eval225($exports);
        $out['2.2.6'] = self::eval226($exports);

        // --- 2.3 / 2.4 headings -----------------------------------------------
        $out['2.3.1'] = self::combineFilters($exports, ['H1:Missing', 'H1:Multiple']);
        $out['2.3.3'] = self::combineFilters($exports, ['H1:Duplicate'], [
            'extra' => static fn (array $r): array => ['h1' => $r['H1-1'] ?? null],
        ]);
        $out['2.4.3'] = self::combineFilters($exports, ['H2:Duplicate'], [
            'extra' => static fn (array $r): array => ['h2' => $r['H2-1'] ?? null],
        ]);

        // --- 2.6 internal linking ---------------------------------------------
        $out['2.6.4'] = self::combineFilters($exports, [
            'Links:Non-Descriptive Anchor Text In Internal Outlinks',
            'Links:Internal Outlinks With No Anchor Text',
        ]);

        // --- 2.7 meta title & description -------------------------------------
        $out['2.7.2'] = self::combineFilters(
            $exports,
            ['Page Titles:Missing', 'Page Titles:Duplicate', 'Page Titles:Multiple', 'Page Titles:Same as H1'],
            ['extra' => static fn (array $r): array => ['title' => $r['Title 1'] ?? null]],
        );
        $out['2.7.3'] = self::combineFilters(
            $exports,
            ['Page Titles:Over X Characters', 'Page Titles:Below X Characters'],
            ['extra' => static fn (array $r): array => ['title' => $r['Title 1'] ?? null, 'lungime' => $r['Title 1 Length'] ?? null]],
        );
        $out['2.7.4'] = self::combineFilters(
            $exports,
            [
                'Meta Description:Missing',
                'Meta Description:Duplicate',
                'Meta Description:Over X Characters',
                'Meta Description:Below X Characters',
            ],
            [
                'notes' => ['Intenția comercială a descrierilor se judecă manual [CONȚINUT].'],
                'extra' => static fn (array $r): array => ['metaDescription' => $r['Meta Description 1'] ?? null],
            ],
        );
        $out['2.7.5'] = self::eval275($exports);

        // --- 2.10 on-page content ---------------------------------------------
        $out['2.10.3'] = self::combineFilters(
            $exports,
            ['Images:Missing Alt Text', 'Images:Missing Alt Attribute'],
            [
                'infoLabels' => ['Images:Images Missing Alt Text Inlinks'],
                'notes' => [
                    'Conformitatea cu formula alt="[Titlu], [x], brand.ro" se judecă manual pe alt-urile '
                        .'exportate; paginile-sursă per imagine sunt în bulk exportul Images Missing Alt Text Inlinks.',
                ],
            ],
        );

        // --- 2.11 / 2.12 schema & pagination ----------------------------------
        $out['2.11.4'] = self::eval2114($exports);
        $out['2.12.1'] = self::eval2121($exports);
        $out['2.12.3'] = self::combineFilters($exports, ['Sitemaps:Non-Indexable URLs in Sitemap'], [
            'notes' => [self::NOTE_PRECONDITIE_SITEMAPS],
            'extra' => static fn (array $r): array => ['indexabilityStatus' => $r['Indexability Status'] ?? null],
        ]);

        // --- 03 technical -----------------------------------------------------
        $out['3.2'] = self::combineFilters($exports, ['Security:HTTP URLs', 'Security:Mixed Content']);
        $out['3.3'] = self::combineFilters(
            $exports,
            ['Response Codes:Internal Redirect Chain', 'Response Codes:Internal Redirect Loop'],
            [
                'infoLabels' => ['Redirects:Redirect Chains'],
                'extra' => static fn (array $r): array => [
                    'status' => $r['Status Code'] ?? null,
                    'redirectUrl' => $r['Redirect URL'] ?? null,
                ],
            ],
        );
        $out['3.10'] = self::combineFilters(
            $exports,
            [
                'Security:Missing HSTS Header',
                'Security:Missing Content-Security-Policy Header',
                'Security:Missing X-Content-Type-Options Header',
                'Security:Missing X-Frame-Options Header',
                'Security:Missing Secure Referrer-Policy Header',
            ],
            ['notes' => ['Headerele homepage-ului măsurate direct (fetch) sunt în evidence.homepageHeaders.']],
        );

        // --- Manual/AI checks with SF context (state=null) --------------------
        $titleSample = array_slice(
            array_values(array_filter(
                $internal->rows,
                static fn (array $r): bool => str_starts_with($r['Content Type'] ?? '', 'text/html')
                    && ($r['Status Code'] ?? '') === '200',
            )),
            0,
            50,
        );

        $out['2.3.2'] = self::evidenceOnly(
            'Potrivirea H1 ↔ cuvânt cheie principal cere GSC (interogări per URL) + judecată manuală [CONȚINUT].',
            ['esantionH1' => array_map(static fn (array $r): array => ['url' => self::rowUrl($r), 'h1' => $r['H1-1'] ?? null], $titleSample)],
        );
        $out['2.3.4'] = self::evidenceOnly(
            'Detecția decorațiunilor în H1 (an, pipe, brand, emoji) se face manual pe valorile exportate.',
            ['esantionH1' => array_map(static fn (array $r): array => ['url' => self::rowUrl($r), 'h1' => $r['H1-1'] ?? null], $titleSample)],
        );
        $out['2.4.1'] = self::evidenceOnly(
            'Potrivirea primului H2 cu cuvântul cheie secundar se judecă manual [CONȚINUT].',
            ['esantionH2' => array_map(static fn (array $r): array => ['url' => self::rowUrl($r), 'h2' => $r['H2-1'] ?? null], $titleSample)],
        );
        $out['2.4.2'] = self::evidenceOnly('Sufixul orientat spre acțiune al H2-urilor comerciale se judecă manual pe template-uri.');
        $out['2.7.1'] = self::evidenceOnly(
            'Conformitatea cu formula „[Keyword] - Brand.ro" se judecă manual/script pe titlurile exportate.',
            ['esantionTitluri' => array_map(static fn (array $r): array => ['url' => self::rowUrl($r), 'title' => $r['Title 1'] ?? null], $titleSample)],
        );

        $sdContains = $exports->exportOf('Structured Data:Contains Structured Data');
        $sdEvidence = [
            'informativ' => [self::fileSummary($sdContains), self::fileSummary($exports->exportOf('Structured Data:Missing'))],
            'esantionScheme' => array_map(
                static fn (array $r): array => [
                    'url' => self::rowUrl($r),
                    'tipuri' => $r['Unique Types'] ?? $r['Total Types'] ?? null,
                    'tip1' => $r['Type-1'] ?? null,
                ],
                array_slice($sdContains->rows, 0, 50),
            ),
        ];
        $out['2.8.2'] = self::evidenceOnly('Comparația FAQ vizibil ↔ JSON-LD FAQPage cere fetch HTML pe template-uri (manual).', $sdEvidence);
        $out['2.9.2'] = self::evidenceOnly('Personalizarea BreadcrumbList per pagină cere fetch JSON-LD pe template-uri (manual).', $sdEvidence);
        $out['2.10.4'] = self::evidenceOnly('Afișarea vizibilă a autorului/datei/categoriei se verifică manual; schema Article e în extras.', $sdEvidence);
        $out['2.11.1'] = self::evidenceOnly('Prezența exactă Product + Offer per template cere fetch JSON-LD (manual).', $sdEvidence);
        $out['2.11.2'] = self::evidenceOnly('Conținutul câmpurilor schemei Article cere fetch JSON-LD pe template (manual).', $sdEvidence);
        $out['2.10.1'] = self::evidenceOnly(
            'Descrierile proprii ale paginilor de categorie se judecă manual [CONȚINUT] (Word Count per URL e în Internal:All).',
        );
        $out['2.12.2'] = self::evidenceOnly(
            'Lista categoriilor care dublează listing-ul e o decizie manuală; URL-urile noindex sunt în extras.',
            ['informativ' => [self::fileSummary($exports->exportOf('Directives:Noindex'))]],
        );
        $out['2.6.1'] = self::evidenceOnly(
            'Logica de navigare părinte → copii → frați se validează manual pe bulk exportul All Inlinks.',
            ['informativ' => [self::fileSummary($exports->exportOf('Links:All Inlinks'))]],
        );

        $images = $exports->exportOf('Internal:Images');
        $formatCounts = [];
        foreach ($images->rows as $r) {
            $ct = trim(explode(';', $r['Content Type'] ?? 'necunoscut')[0]);
            $formatCounts[$ct] = ($formatCounts[$ct] ?? 0) + 1;
        }
        $out['3.5'] = self::evidenceOnly(
            'Verdictul WebP/formate moderne vine din PSI (auditul modern-image-formats); '
                .'distribuția formatelor servite măsurată de Screaming Frog e context complementar.',
            ['formateImagini' => $formatCounts, 'informativ' => [self::fileSummary($images)]],
        );

        $internalSearch = $exports->exportOf('URL:Internal Search');
        ['affected' => $searchUrls, 'truncated' => $searchTruncated] = self::capAffected(array_map(
            static fn (array $r): array => ['url' => self::rowUrl($r), 'indexability' => $r['Indexability'] ?? null],
            $internalSearch->rows,
        ));
        $out['3.9'] = self::evidenceOnly(
            'Funcționalitatea căutării interne și noindex-ul rezultatelor se verifică manual; '
                .'URL-urile de căutare internă detectate la crawl sunt în extras.',
            [
                'informativ' => [self::fileSummary($internalSearch)],
                'affected' => $searchUrls,
                'truncated' => $searchTruncated,
            ],
        );
        $out['3.4'] = self::evidenceOnly(
            'Integritatea sitemap-ului și realismul lastmod se verifică prin fetch pe sitemap + comparație manuală.',
            [
                'informativ' => [
                    self::fileSummary($exports->exportOf('Sitemaps:URLs not in Sitemap')),
                    self::fileSummary($exports->exportOf('Sitemaps:Non-Indexable URLs in Sitemap')),
                    self::fileSummary($exports->exportOf('Sitemaps:Orphan URLs')),
                ],
            ],
        );
        $out['6.3'] = self::evidenceOnly(
            'Necesită al doilea crawl cu randare JavaScript (diff Word Count/H1/Title per URL între Text Only '
                .'și JS) — neexecutat în această etapă; confirmare punctuală prin fetch raw pe template-uri.',
        );

        return $out;
    }
}
