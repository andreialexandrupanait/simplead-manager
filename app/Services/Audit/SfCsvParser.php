<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\ParsedCsv;

/**
 * Parser for Screaming Frog CSV exports (v2 methodology). Port of
 * src/lib/evaluation/v2/csv.ts.
 *
 * The real format (validated on SF 24.3): a UTF-8 BOM at the start; every value
 * quoted, with doubled quotes inside and commas/newlines inside values; the
 * first line is the header with the interface column names ("Address",
 * "Status Code", "Indexability", "Title 1", …). We cap the number of parsed rows
 * so huge exports (all_inlinks.csv can have hundreds of thousands of rows) do
 * not sit in memory — evidence is capped at 500 downstream anyway.
 */
final class SfCsvParser
{
    /** Default row cap per file. */
    public const DEFAULT_ROW_LIMIT = 50_000;

    /** Bulk exports (all_inlinks.csv) are only ever used as evidence. */
    public const BULK_ROW_LIMIT = 5_000;

    public static function parse(string $content, int $rowLimit = self::DEFAULT_ROW_LIMIT): ParsedCsv
    {
        // Strip a leading UTF-8 BOM if present.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return new ParsedCsv([], false);
        }
        fwrite($stream, $content);
        rewind($stream);

        // Empty escape string → RFC-4180 doubled-quote handling (SF uses "" to
        // escape a quote, not a backslash).
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (! is_array($header) || $header === [null]) {
            fclose($stream);

            return new ParsedCsv([], false);
        }
        $columns = array_map(static fn ($h): string => (string) ($h ?? ''), $header);

        $rows = [];
        $truncated = false;
        while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($record === [null]) {
                continue; // blank line
            }
            if (count($rows) >= $rowLimit) {
                $truncated = true;
                break;
            }
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = isset($record[$i]) ? (string) ($record[$i] ?? '') : '';
            }
            $rows[] = $row;
        }
        fclose($stream);

        return new ParsedCsv($rows, $truncated);
    }
}
