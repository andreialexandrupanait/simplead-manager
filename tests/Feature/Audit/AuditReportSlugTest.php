<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\AuditReportSlug;
use PHPUnit\Framework\TestCase;

/**
 * Faza D6: the public report slug. Port of report/slug.ts. Pure.
 */
class AuditReportSlugTest extends TestCase
{
    public function test_base_from_domain_normalizes(): void
    {
        $this->assertSame('select-soft-ro', AuditReportSlug::baseFromDomain('https://Select-Soft.ro/produse'));
        $this->assertSame('x-ro', AuditReportSlug::baseFromDomain('http://x.ro'));
    }

    public function test_is_usable_rejects_short_and_reserved(): void
    {
        $this->assertTrue(AuditReportSlug::isUsable('select-soft-ro'));
        $this->assertFalse(AuditReportSlug::isUsable('a'));   // too short
        $this->assertFalse(AuditReportSlug::isUsable('api')); // reserved
        $this->assertFalse(AuditReportSlug::isUsable('r'));   // reserved
    }

    public function test_resolve_appends_a_suffix_when_taken(): void
    {
        $this->assertSame('acme-ro', AuditReportSlug::resolve('acme-ro', static fn (): bool => false));

        $taken = ['acme-ro' => true, 'acme-ro-2' => true];
        $this->assertSame('acme-ro-3', AuditReportSlug::resolve('acme-ro', static fn (string $s): bool => isset($taken[$s])));
    }

    public function test_resolve_skips_reserved_base(): void
    {
        $this->assertSame('api-2', AuditReportSlug::resolve('api', static fn (): bool => false));
    }
}
