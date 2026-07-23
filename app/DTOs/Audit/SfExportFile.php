<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * A resolved Screaming Frog export — either found on disk or absent. Port of
 * SfExportFile (src/lib/evaluation/v2/exports.ts).
 *
 * Absent + requested carries the load-bearing "--skip-empty" semantic: for the
 * labels requested at crawl time, a missing file means an empty SF filter, which
 * is POSITIVE evidence (EXISTA). Evaluators (Faza D3) decide that semantic.
 */
final readonly class SfExportFile
{
    /**
     * @param  string  $label  the CLI label requested, e.g. "URL:Uppercase"
     * @param  bool  $requested  requested at crawl time (--export-tabs / --bulk-export / --save-report)
     * @param  bool  $present  the file exists on disk
     * @param  string|null  $fileName  the resolved file name (null when absent)
     * @param  list<array<string, string>>  $rows
     * @param  bool  $parseTruncated  parsing stopped at the row cap (very large file)
     */
    public function __construct(
        public string $label,
        public bool $requested,
        public bool $present,
        public ?string $fileName,
        public array $rows,
        public bool $parseTruncated,
    ) {}
}
