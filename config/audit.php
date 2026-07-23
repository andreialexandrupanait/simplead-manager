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

    /*
    |--------------------------------------------------------------------------
    | AI (Anthropic) — qualitative evaluation + card drafting
    |--------------------------------------------------------------------------
    | Reuses the shared ANTHROPIC_API_KEY. The model is pinned to the current
    | Sonnet line (best quality/cost for the per-module judgement); do not change
    | it without re-checking cost and strict tool-use behavior. When the key is
    | absent the AI tier is simply skipped (deterministic results stand).
    */
    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AUDIT_AI_MODEL', 'claude-sonnet-5'),
        'base_url' => env('AUDIT_AI_BASE_URL', 'https://api.anthropic.com/v1/messages'),
        'anthropic_version' => '2023-06-01',
        'timeout' => (int) env('AUDIT_AI_TIMEOUT', 120),
        'eval_max_tokens' => 8000,
    ],

];
