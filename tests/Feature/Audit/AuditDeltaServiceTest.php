<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Models\Prospect;
use App\Services\Audit\AuditDeltaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D5: the run-to-run delta between two audits of the same target.
 */
class AuditDeltaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function putResult(Audit $audit, AuditCheck $check, ?CheckState $state): void
    {
        AuditCheckResult::query()->create([
            'audit_id' => $audit->id,
            'audit_check_id' => $check->id,
            'state' => $state,
            'evidence' => [],
            'state_set_by' => 'auto',
        ]);
    }

    public function test_it_classifies_implemented_regressed_changed_and_unchanged(): void
    {
        $prospect = Prospect::factory()->create();
        $previous = Audit::factory()->create(['prospect_id' => $prospect->id, 'site_id' => null]);
        $current = Audit::factory()->create(['prospect_id' => $prospect->id, 'site_id' => null]);
        $checks = AuditCheck::query()->orderBy('sort_order')->limit(4)->get();

        // previous → current
        $this->putResult($previous, $checks[0], CheckState::NuExista); // → EXISTA  = implemented
        $this->putResult($previous, $checks[1], CheckState::Exista);   // → NU_EXISTA = regressed
        $this->putResult($previous, $checks[2], CheckState::Exista);   // → EXISTA  = unchanged
        $this->putResult($previous, $checks[3], null);                 // → NU_SE_APLICA = changed

        $this->putResult($current, $checks[0], CheckState::Exista);
        $this->putResult($current, $checks[1], CheckState::NuExista);
        $this->putResult($current, $checks[2], CheckState::Exista);
        $this->putResult($current, $checks[3], CheckState::NuSeAplica);

        $delta = (new AuditDeltaService)->compare($current, $previous);

        $this->assertSame(1, $delta['implemented']);
        $this->assertSame(1, $delta['regressed']);
        $this->assertSame(1, $delta['changed']);
        $this->assertSame(1, $delta['unchanged']);
        $this->assertSame(4, $delta['total']);
        $this->assertCount(3, $delta['changes']); // everything except the unchanged one

        $implemented = collect($delta['changes'])->firstWhere('kind', 'implemented');
        $this->assertSame($checks[0]->key, $implemented['key']);
        $this->assertSame('NU_EXISTA', $implemented['from']);
        $this->assertSame('EXISTA', $implemented['to']);
    }

    public function test_previous_for_target_finds_the_earlier_audit(): void
    {
        $prospect = Prospect::factory()->create();
        $previous = Audit::factory()->create(['prospect_id' => $prospect->id, 'site_id' => null]);
        $this->putResult($previous, AuditCheck::query()->firstOrFail(), CheckState::Exista);
        $current = Audit::factory()->create(['prospect_id' => $prospect->id, 'site_id' => null]);

        $this->assertSame($previous->id, $current->previousForTarget()?->id);
    }

    public function test_previous_for_target_is_null_for_a_first_audit(): void
    {
        $audit = Audit::factory()->create();

        $this->assertNull($audit->previousForTarget());
    }
}
