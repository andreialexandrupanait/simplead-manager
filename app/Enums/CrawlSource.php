<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a crawl's export folder came from. Both feed the same ingestion
 * normalizer (SfCrawlLoader) — a folder of SF CSVs either way.
 */
enum CrawlSource: string
{
    case SfHeadless = 'sf_headless';
    case ManualUpload = 'manual_upload';
}
