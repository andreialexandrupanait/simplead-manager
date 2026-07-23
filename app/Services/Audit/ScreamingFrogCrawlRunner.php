<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * The production SF runner: execs `screamingfrogseospider` headless with the
 * exact argument set validated on SF 24.3 (buildSfArgs — no --config, no --use-*
 * flags; the default config is validated). 30 min then SIGKILL. Port of
 * defaultSfRunner + buildSfArgs (src/lib/jobs/sf-crawl.ts).
 */
final class ScreamingFrogCrawlRunner implements SfCrawlRunner
{
    /**
     * The CLI arguments for the standard (Text Only) crawl. Labels are validated
     * on SF 24.3. No --timestamped-output: the folder is predictable and
     * --overwrite refreshes it.
     *
     * @return list<string>
     */
    public static function buildSfArgs(string $url, string $outputFolder): array
    {
        return [
            '--crawl', $url,
            '--headless',
            '--output-folder', $outputFolder,
            '--overwrite',
            '--save-crawl',
            '--export-format', 'csv',
            '--export-tabs', implode(',', SfExportRegistry::EXPORT_TABS),
            '--bulk-export', implode(',', SfExportRegistry::BULK_EXPORTS),
            '--save-report', implode(',', SfExportRegistry::SAVE_REPORTS),
            '--skip-empty',
        ];
    }

    public function crawl(string $url, string $outputFolder): void
    {
        $binary = (string) config('audit.screaming_frog.binary');
        $timeout = (int) config('audit.screaming_frog.timeout');

        try {
            $result = Process::path($outputFolder)
                ->timeout($timeout)
                ->run(array_merge([$binary], self::buildSfArgs($url, $outputFolder)));
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException(
                "Screaming Frog crawl exceeded {$timeout}s and was killed.",
                previous: $e,
            );
        }

        if ($result->failed()) {
            $tail = mb_substr(trim($result->errorOutput()), -500);
            throw new \RuntimeException(
                'Screaming Frog failed'.($tail !== '' ? " — stderr: {$tail}" : '').'.',
            );
        }
    }
}
