<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Runs a Screaming Frog headless crawl into an output folder. Abstracted so the
 * crawl job can be driven by a fake in tests (real SF is never run in tests).
 */
interface SfCrawlRunner
{
    /**
     * Crawl $url, writing the requested CSV exports into $outputFolder.
     *
     * @throws \RuntimeException on failure or timeout
     */
    public function crawl(string $url, string $outputFolder): void;
}
