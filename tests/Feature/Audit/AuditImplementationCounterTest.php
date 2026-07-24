<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\AuditImplementationCounter;
use PHPUnit\Framework\TestCase;

/**
 * Faza D5: the "X of Y implemented" aggregation. Port of implementationCounter
 * (v2.ts). Pure — no DB.
 */
class AuditImplementationCounterTest extends TestCase
{
    public function test_only_validated_cards_count_toward_total(): void
    {
        $result = AuditImplementationCounter::count([
            ['validation' => 'APROBAT', 'implementation' => 'IMPLEMENTAT'],
            ['validation' => 'EDITAT', 'implementation' => 'NEIMPLEMENTAT'],
            ['validation' => 'DRAFT_AI', 'implementation' => 'IMPLEMENTAT'], // ignored (unvalidated)
            ['validation' => 'RESPINS', 'implementation' => 'IMPLEMENTAT'],  // ignored (rejected)
        ]);

        $this->assertSame(['implemented' => 1, 'total' => 2], $result);
    }

    public function test_empty_is_zero(): void
    {
        $this->assertSame(['implemented' => 0, 'total' => 0], AuditImplementationCounter::count([]));
    }

    public function test_all_neimplementat_at_delivery(): void
    {
        $result = AuditImplementationCounter::count([
            ['validation' => 'APROBAT', 'implementation' => 'NEIMPLEMENTAT'],
            ['validation' => 'EDITAT', 'implementation' => 'NEIMPLEMENTAT'],
        ]);

        $this->assertSame(['implemented' => 0, 'total' => 2], $result);
    }
}
