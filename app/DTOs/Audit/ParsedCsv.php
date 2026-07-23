<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * The result of parsing one Screaming Frog CSV export. Port of ParsedCsv from
 * the audit repo (src/lib/evaluation/v2/csv.ts).
 */
final readonly class ParsedCsv
{
    /**
     * @param  list<array<string, string>>  $rows  column name (verbatim from header) → value
     * @param  bool  $parseTruncated  the file had more rows than the parse limit
     */
    public function __construct(
        public array $rows,
        public bool $parseTruncated,
    ) {}
}
