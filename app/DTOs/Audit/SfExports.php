<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * All resolved exports of one crawl folder — the normalized shape shared by both
 * ingestion sources (SF headless output and a manual upload are both just a
 * directory of CSVs). Port of SfExports (src/lib/evaluation/v2/exports.ts).
 */
final readonly class SfExports
{
    /**
     * @param  string  $dir  the crawl folder
     * @param  array<string, SfExportFile>  $files  requested label → resolved file
     * @param  list<string>  $unmatchedFiles  CSVs in the folder used by no label (diagnostic)
     */
    public function __construct(
        public string $dir,
        public array $files,
        public array $unmatchedFiles,
    ) {}

    public function file(string $label): ?SfExportFile
    {
        return $this->files[$label] ?? null;
    }

    /**
     * Always returns an SfExportFile for the label — a synthetic
     * "not requested, absent" one when the label was not part of the crawl set.
     * Port of exportOf() (src/lib/evaluation/v2/exports.ts).
     */
    public function exportOf(string $label): SfExportFile
    {
        return $this->files[$label]
            ?? new SfExportFile($label, requested: false, present: false, fileName: null, rows: [], parseTruncated: false);
    }
}
