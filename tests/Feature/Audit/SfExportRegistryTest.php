<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\SfExportRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Faza D (D2a): the SF export registry — the label lists and the file-name
 * resolution ported verbatim from the audit repo (exports.ts).
 */
class SfExportRegistryTest extends TestCase
{
    public function test_the_registry_holds_the_57_2_5_methodology_exports(): void
    {
        $this->assertCount(57, SfExportRegistry::EXPORT_TABS);
        $this->assertCount(2, SfExportRegistry::BULK_EXPORTS);
        $this->assertCount(5, SfExportRegistry::SAVE_REPORTS);

        // No duplicates within the tab list.
        $this->assertSame(
            SfExportRegistry::EXPORT_TABS,
            array_values(array_unique(SfExportRegistry::EXPORT_TABS)),
        );
    }

    public function test_normalize_name_lowercases_and_underscores(): void
    {
        $this->assertSame(
            'response_codes_blocked_by_robots_txt',
            SfExportRegistry::normalizeName('Response Codes:Blocked by Robots.txt'),
        );
        $this->assertSame('internal_all', SfExportRegistry::normalizeName('internal_all.csv'));
    }

    public function test_fuzzy_key_collapses_numeric_and_x_segments_but_not_mixed(): void
    {
        // "Over X Characters" and "over 60 characters" collapse to the same key.
        $this->assertSame(
            SfExportRegistry::fuzzyKey('Page Titles:Over X Characters'),
            SfExportRegistry::fuzzyKey('page_titles_over_60_characters'),
        );
        // Mixed segments stay: h1 must not collide with h2.
        $this->assertNotSame(
            SfExportRegistry::fuzzyKey('H1:Duplicate'),
            SfExportRegistry::fuzzyKey('H2:Duplicate'),
        );
    }

    /**
     * @param  list<string>  $filesInDir
     */
    #[DataProvider('resolutionCases')]
    public function test_resolve_file_name(string $label, array $filesInDir, ?string $expected): void
    {
        $this->assertSame($expected, SfExportRegistry::resolveFileName($label, $filesInDir));
    }

    /**
     * @return iterable<string, array{string, list<string>, ?string}>
     */
    public static function resolutionCases(): iterable
    {
        yield 'exact tab match' => [
            'Internal:All',
            ['internal_all.csv', 'noise.csv'],
            'internal_all.csv',
        ];

        yield 'fuzzy numeric threshold' => [
            'Page Titles:Over X Characters',
            ['page_titles_over_60_characters.csv'],
            'page_titles_over_60_characters.csv',
        ];

        yield 'hyphens removed, not underscored' => [
            'Security:Missing Content-Security-Policy Header',
            ['security_missing_contentsecuritypolicy_header.csv'],
            'security_missing_contentsecuritypolicy_header.csv',
        ];

        yield 'bulk export resolves via after-colon candidate' => [
            'Links:All Inlinks',
            ['all_inlinks.csv'],
            'all_inlinks.csv',
        ];

        yield 'report resolves via after-colon candidate' => [
            'Crawl Overview',
            ['crawl_overview.csv'],
            'crawl_overview.csv',
        ];

        yield 'absent → null' => [
            'H1:Missing',
            ['internal_all.csv'],
            null,
        ];

        yield 'non-csv ignored' => [
            'Internal:All',
            ['internal_all.txt'],
            null,
        ];
    }
}
