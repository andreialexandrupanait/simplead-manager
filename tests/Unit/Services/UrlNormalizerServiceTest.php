<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SeoAudit\UrlNormalizerService;
use PHPUnit\Framework\TestCase;

/**
 * P3-20: the normalizer used to drop query strings, collapsing ?p=1 and ?p=2
 * into the same hash so paginated / parameter-driven pages were treated as one.
 */
class UrlNormalizerServiceTest extends TestCase
{
    public function test_significant_query_strings_are_preserved_and_distinct(): void
    {
        $one = UrlNormalizerService::normalize('https://acme.com/blog?p=1');
        $two = UrlNormalizerService::normalize('https://acme.com/blog?p=2');

        $this->assertStringContainsString('p=1', $one);
        $this->assertNotSame($one, $two);
        $this->assertNotSame(
            UrlNormalizerService::hash('https://acme.com/blog?p=1'),
            UrlNormalizerService::hash('https://acme.com/blog?p=2'),
        );
    }

    public function test_tracking_params_are_stripped_so_they_still_dedupe(): void
    {
        $clean = UrlNormalizerService::normalize('https://acme.com/page');
        $tracked = UrlNormalizerService::normalize('https://acme.com/page?utm_source=twitter&fbclid=abc');

        $this->assertSame($clean, $tracked);
    }

    public function test_query_param_order_is_normalized(): void
    {
        $this->assertSame(
            UrlNormalizerService::normalize('https://acme.com/s?a=1&b=2'),
            UrlNormalizerService::normalize('https://acme.com/s?b=2&a=1'),
        );
    }
}
