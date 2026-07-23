<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\SfExportFile;
use App\DTOs\Audit\SfExports;

/**
 * Loads every requested export from a crawl folder into the normalized SfExports
 * shape. Port of loadSfExports (src/lib/evaluation/v2/exports.ts).
 *
 * This is the single ingestion normalizer for Faza D: it treats a folder of CSVs
 * identically whether SF headless produced it or a user uploaded it manually.
 * Absent files stay in the map with present=false and rows=[] — evaluators
 * (Faza D3) decide the semantic (absent + requested = empty filter).
 */
final class SfCrawlLoader
{
    public function load(string $dir): SfExports
    {
        $filesInDir = [];
        if (is_dir($dir)) {
            $entries = scandir($dir);
            if ($entries !== false) {
                $filesInDir = array_values(array_filter(
                    $entries,
                    static fn (string $e): bool => $e !== '.' && $e !== '..'
                        && is_file($dir.DIRECTORY_SEPARATOR.$e),
                ));
            }
        }

        $files = [];
        $used = [];

        $loadOne = function (string $label, int $rowLimit) use ($dir, $filesInDir, &$files, &$used): void {
            $fileName = SfExportRegistry::resolveFileName($label, $filesInDir);
            if ($fileName === null) {
                $files[$label] = new SfExportFile($label, true, false, null, [], false);

                return;
            }

            $used[$fileName] = true;
            try {
                $content = @file_get_contents($dir.DIRECTORY_SEPARATOR.$fileName);
                if ($content === false) {
                    throw new \RuntimeException('unreadable');
                }
                $parsed = SfCsvParser::parse($content, $rowLimit);
                $files[$label] = new SfExportFile($label, true, true, $fileName, $parsed->rows, $parsed->parseTruncated);
            } catch (\Throwable) {
                // Corrupt/unreadable: treat as empty, but present on disk — not positive evidence.
                $files[$label] = new SfExportFile($label, true, true, $fileName, [], false);
            }
        };

        foreach (SfExportRegistry::EXPORT_TABS as $label) {
            $loadOne($label, SfCsvParser::DEFAULT_ROW_LIMIT);
        }
        foreach (SfExportRegistry::BULK_EXPORTS as $label) {
            $loadOne($label, SfCsvParser::BULK_ROW_LIMIT);
        }
        foreach (SfExportRegistry::SAVE_REPORTS as $label) {
            $loadOne($label, SfCsvParser::DEFAULT_ROW_LIMIT);
        }

        $unmatched = [];
        foreach ($filesInDir as $f) {
            if (preg_match('/\.csv$/i', $f) === 1 && ! isset($used[$f])) {
                $unmatched[] = $f;
            }
        }
        sort($unmatched);

        return new SfExports($dir, $files, $unmatched);
    }
}
