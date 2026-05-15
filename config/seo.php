<?php

declare(strict_types=1);

return [
    'crawler' => ['user_agent' => 'SimpleAd-SEO/1.0', 'concurrency' => 3, 'delay_ms' => 200, 'default_max_pages' => 500, 'max_pages_hard_limit' => 2000, 'timeout_per_page' => 15],
    'analysis' => ['title_min_length' => 30, 'title_max_length' => 60, 'description_min_length' => 70, 'description_max_length' => 160, 'max_redirect_chain' => 3, 'large_image_threshold_kb' => 300, 'min_word_count' => 300, 'max_external_link_checks' => 500, 'max_image_checks' => 100, 'url_max_length' => 115, 'pagespeed_max_age_days' => 7],
    'scoring' => ['weights' => ['technical' => 40, 'on_page' => 30, 'performance' => 20, 'other' => 10], 'severity_penalties' => ['critical' => 15, 'high' => 8, 'medium' => 3, 'low' => 1, 'info' => 0]],
    'retention_days' => 90,
];
