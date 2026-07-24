<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\AuditAutoApprover;
use PHPUnit\Framework\TestCase;

/**
 * Faza D: the auto-approvable classification. Port of auto-approve.test.ts. Pure —
 * no DB. Integrity rule: only deterministic-with-evidence recommendations
 * auto-approve; AI/manual judgement never does.
 */
class AuditAutoApproverTest extends TestCase
{
    public function test_is_deterministic_check_true_when_all_sources_deterministic(): void
    {
        $this->assertTrue(AuditAutoApprover::isDeterministicCheck([['type' => 'sf_export']]));
        $this->assertTrue(AuditAutoApprover::isDeterministicCheck([['type' => 'fetch'], ['type' => 'psi']]));
        $this->assertTrue(AuditAutoApprover::isDeterministicCheck([['type' => 'sf_report'], ['type' => 'sf_bulk_export']]));
    }

    public function test_is_deterministic_check_false_when_any_source_is_judgement(): void
    {
        $this->assertFalse(AuditAutoApprover::isDeterministicCheck([['type' => 'sf_export'], ['type' => 'manual']]));
        foreach (['web', 'gsc', 'ga4', 'bing', 'ai'] as $type) {
            $this->assertFalse(AuditAutoApprover::isDeterministicCheck([['type' => $type]]), $type);
        }
    }

    public function test_is_deterministic_check_false_for_missing_empty_or_malformed(): void
    {
        $this->assertFalse(AuditAutoApprover::isDeterministicCheck([]));
        $this->assertFalse(AuditAutoApprover::isDeterministicCheck(null));
        $this->assertFalse(AuditAutoApprover::isDeterministicCheck('sf_export'));
        $this->assertFalse(AuditAutoApprover::isDeterministicCheck([['nope' => 1]]));
    }

    public function test_deterministic_source_types_match_the_methodology(): void
    {
        $types = AuditAutoApprover::DETERMINISTIC_SOURCE_TYPES;
        sort($types);
        $this->assertSame(['fetch', 'psi', 'sf_bulk_export', 'sf_export', 'sf_report'], $types);
    }

    public function test_deterministic_keys_of_returns_only_fully_deterministic_checks(): void
    {
        $keys = AuditAutoApprover::deterministicKeysOf([
            ['key' => '2.1.1', 'sources' => [['type' => 'sf_export']]],
            ['key' => '2.7.1', 'sources' => [['type' => 'sf_export'], ['type' => 'manual']]],
            ['key' => '3.4', 'sources' => [['type' => 'web']]],
            ['key' => '3.5', 'sources' => [['type' => 'fetch']]],
        ]);

        $this->assertEqualsCanonicalizing(['2.1.1', '3.5'], array_keys($keys));
    }

    public function test_is_auto_approvable(): void
    {
        $det = ['2.1.1' => true, '3.5' => true];

        $this->assertTrue(AuditAutoApprover::isAutoApprovable(['needsVerification' => false, 'checkIds' => ['2.1.1']], $det));
        $this->assertTrue(AuditAutoApprover::isAutoApprovable(['needsVerification' => false, 'checkIds' => ['2.1.1', '3.5']], $det));

        // needsVerification blocks it even when deterministic.
        $this->assertFalse(AuditAutoApprover::isAutoApprovable(['needsVerification' => true, 'checkIds' => ['2.1.1']], $det));
        // Any non-deterministic covered check blocks it.
        $this->assertFalse(AuditAutoApprover::isAutoApprovable(['needsVerification' => false, 'checkIds' => ['2.7.1']], $det));
        $this->assertFalse(AuditAutoApprover::isAutoApprovable(['needsVerification' => false, 'checkIds' => ['2.1.1', '2.7.1']], $det));
        // No covered checks → not auto-approvable.
        $this->assertFalse(AuditAutoApprover::isAutoApprovable(['needsVerification' => false, 'checkIds' => []], $det));
    }
}
