<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\SfCrawlLoader;
use App\Services\Audit\SfCsvParser;
use Tests\TestCase;

/**
 * Faza D (D2a): the CSV parser + the crawl-folder loader (the single ingestion
 * normalizer shared by SF headless output and manual uploads).
 */
class SfCrawlLoaderTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/Audit/crawl-sample';

    public function test_parser_strips_bom_and_handles_quoted_values(): void
    {
        $content = (string) file_get_contents(self::FIXTURE_DIR.'/internal_all.csv');
        $parsed = SfCsvParser::parse($content);

        $this->assertFalse($parsed->parseTruncated);
        $this->assertCount(2, $parsed->rows);
        // BOM stripped → the first header is a clean "Address".
        $this->assertArrayHasKey('Address', $parsed->rows[0]);
        $this->assertSame('https://x.ro/', $parsed->rows[0]['Address']);
        // Embedded comma + doubled quotes decoded.
        $this->assertSame('Hi, "there"', $parsed->rows[0]['Title 1']);
        $this->assertSame('Plain', $parsed->rows[1]['Title 1']);
    }

    public function test_parser_reports_truncation_past_the_row_cap(): void
    {
        $csv = "\"Address\"\r\n";
        for ($i = 0; $i < 5; $i++) {
            $csv .= "\"https://x.ro/{$i}\"\r\n";
        }
        $parsed = SfCsvParser::parse($csv, rowLimit: 3);

        $this->assertTrue($parsed->parseTruncated);
        $this->assertCount(3, $parsed->rows);
    }

    public function test_loader_resolves_present_and_absent_exports(): void
    {
        $exports = (new SfCrawlLoader)->load(self::FIXTURE_DIR);

        // Every requested label is in the map (57 + 2 + 5).
        $this->assertCount(64, $exports->files);

        // Present exports (exact / fuzzy / hyphen / after-colon / report).
        $internal = $exports->file('Internal:All');
        $this->assertNotNull($internal);
        $this->assertTrue($internal->present);
        $this->assertSame('internal_all.csv', $internal->fileName);
        $this->assertCount(2, $internal->rows);

        $this->assertTrue($exports->file('Page Titles:Over X Characters')->present);
        $this->assertTrue($exports->file('Security:Missing Content-Security-Policy Header')->present);
        $this->assertTrue($exports->file('Links:All Inlinks')->present);
        $this->assertTrue($exports->file('Crawl Overview')->present);

        // Absent requested label → present=false, empty rows (skip-empty semantic).
        $absent = $exports->file('H1:Missing');
        $this->assertNotNull($absent);
        $this->assertFalse($absent->present);
        $this->assertNull($absent->fileName);
        $this->assertSame([], $absent->rows);

        // The stray CSV surfaces as a diagnostic, matched files do not.
        $this->assertContains('some_random_export.csv', $exports->unmatchedFiles);
        $this->assertNotContains('internal_all.csv', $exports->unmatchedFiles);
    }

    public function test_loader_on_a_missing_directory_marks_everything_absent(): void
    {
        $exports = (new SfCrawlLoader)->load(self::FIXTURE_DIR.'/does-not-exist');

        $this->assertCount(64, $exports->files);
        $this->assertFalse($exports->file('Internal:All')->present);
        $this->assertSame([], $exports->unmatchedFiles);
    }
}
