<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Crawl storage
    |--------------------------------------------------------------------------
    | Where Screaming Frog writes a crawl's CSV exports. One folder per audit run
    | (audit_<id>). Manual uploads land in the same layout so the ingestion path
    | (SfCrawlLoader) is identical.
    */
    'crawls_base_dir' => storage_path('app/audit/crawls'),

    /*
    |--------------------------------------------------------------------------
    | Screaming Frog headless
    |--------------------------------------------------------------------------
    | The binary + limits for the automated crawl on dasher. Heap and license /
    | EULA live in host config (~/.screamingfrogseospider, spider.config
    | eula.accepted=15) — see docs/audit/screaming-frog/. Politeness (1 URL/sec)
    | is host config too; we never mask the UA without the client's consent.
    */
    'screaming_frog' => [
        'binary' => env('SF_BINARY', 'screamingfrogseospider'),
        // 30 min then SIGKILL — matches the audit repo's SF_TIMEOUT_MS.
        'timeout' => (int) env('SF_TIMEOUT_SECONDS', 1800),
    ],

];
