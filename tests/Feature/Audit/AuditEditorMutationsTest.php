<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditStatus;
use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Services\Audit\AuditAutoApprover;
use App\Services\Audit\AuditEditorMutations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D: the validation-editor mutations. Port of v2-mutations.test.ts, adapted
 * to the flattened schema (no module instances).
 */
class AuditEditorMutationsTest extends TestCase
{
    use RefreshDatabase;

    private AuditEditorMutations $mut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mut = new AuditEditorMutations;
    }

    private function firstCheckKey(): string
    {
        return (string) AuditCheck::query()->value('key');
    }

    private function markGap(Audit $audit, string $key): void
    {
        $id = AuditCheck::query()->where('key', $key)->value('id');
        AuditCheckResult::query()->updateOrCreate(
            ['audit_id' => $audit->id, 'audit_check_id' => $id],
            ['state' => CheckState::NuExista, 'evidence' => [], 'state_set_by' => 'auto'],
        );
    }

    public function test_set_check_state_upserts_and_marks_manual(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);
        $key = $this->firstCheckKey();

        $error = $this->mut->setCheckState($audit, $key, CheckState::Exista);

        $this->assertNull($error);
        $checkId = AuditCheck::query()->where('key', $key)->value('id');
        $result = AuditCheckResult::query()->where('audit_id', $audit->id)->where('audit_check_id', $checkId)->firstOrFail();
        $this->assertSame(CheckState::Exista, $result->state);
        $this->assertSame('manual', $result->state_set_by);
    }

    public function test_set_check_state_stores_reason_only_for_nu_se_aplica(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);
        $key = $this->firstCheckKey();

        $this->mut->setCheckState($audit, $key, CheckState::NuSeAplica, 'Site fără e-commerce.');
        $checkId = AuditCheck::query()->where('key', $key)->value('id');
        $result = AuditCheckResult::query()->where('audit_id', $audit->id)->where('audit_check_id', $checkId)->firstOrFail();
        $this->assertSame('Site fără e-commerce.', $result->evidence['reason']);

        // Switching away clears the reason.
        $this->mut->setCheckState($audit, $key, CheckState::Exista);
        $result->refresh();
        $this->assertArrayNotHasKey('reason', $result->evidence);
    }

    public function test_mutations_are_rejected_when_not_editable(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Validat]);

        $error = $this->mut->setCheckState($audit, $this->firstCheckKey(), CheckState::Exista);
        $this->assertNotNull($error);
        $this->assertDatabaseCount('audit_check_results', 0);
    }

    public function test_draft_advances_to_in_validare_on_first_mutation(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Draft]);

        $this->mut->setCheckState($audit, $this->firstCheckKey(), CheckState::Exista);

        $this->assertSame(AuditStatus::InValidare, $audit->fresh()->status);
    }

    public function test_upsert_recommendation_requires_all_checks_to_be_gaps(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $key = $this->firstCheckKey();

        // Not a gap yet → rejected.
        $result = $this->mut->upsertRecommendation($audit, null, [
            'title' => 'Fix', 'impact' => 'mare', 'checkIds' => [$key],
        ]);
        $this->assertArrayHasKey('error', $result);

        // Mark it NU_EXISTA → accepted.
        $this->markGap($audit, $key);
        $result = $this->mut->upsertRecommendation($audit, null, [
            'title' => 'Fix titluri', 'impact' => 'mare', 'diagnostic' => 'Lipsesc titlurile.', 'checkIds' => [$key],
        ]);
        $this->assertArrayHasKey('cardId', $result);
        $this->assertDatabaseHas('audit_cards', [
            'audit_id' => $audit->id, 'title' => 'Fix titluri', 'validation' => 'EDITAT', 'auto_approved' => false,
        ]);
    }

    public function test_upsert_recommendation_drops_empty_payload_components(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $key = $this->firstCheckKey();
        $this->markGap($audit, $key);

        $result = $this->mut->upsertRecommendation($audit, null, [
            'title' => 'Fix', 'impact' => 'mic', 'checkIds' => [$key],
            'payload' => ['table' => ['rows' => []], 'callouts' => [], 'codeBlocks' => [['lang' => 'html', 'code' => '<x>']]],
        ]);

        $card = AuditCard::query()->findOrFail($result['cardId']);
        $this->assertArrayNotHasKey('table', $card->payload);
        $this->assertArrayNotHasKey('callouts', $card->payload);
        $this->assertArrayHasKey('codeBlocks', $card->payload);
    }

    public function test_set_validation_approves_and_rejects_only_drafts(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $draft = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->create();

        $this->assertNull($this->mut->setValidation($audit, $draft->id, 'APROBAT'));
        $this->assertSame('APROBAT', $draft->fresh()->validation);

        // Re-approving a non-draft is rejected.
        $this->assertNotNull($this->mut->setValidation($audit, $draft->id, 'RESPINS'));
    }

    public function test_set_validation_blocks_approve_when_needs_verification(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $draft = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->needsVerification()->create();

        $error = $this->mut->setValidation($audit, $draft->id, 'APROBAT');
        $this->assertNotNull($error);
        $this->assertSame('DRAFT_AI', $draft->fresh()->validation);

        // But it can be rejected.
        $this->assertNull($this->mut->setValidation($audit, $draft->id, 'RESPINS'));
    }

    public function test_approve_all_safe_only_approves_deterministic_drafts(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);

        // Find a fully-deterministic check key and a non-deterministic one from the seed.
        $detKey = AuditCheck::query()->get(['key', 'sources'])
            ->first(static fn (AuditCheck $c): bool => AuditAutoApprover::isDeterministicCheck($c->sources))?->key;
        $nonDetKey = AuditCheck::query()->get(['key', 'sources'])
            ->first(static fn (AuditCheck $c): bool => ! AuditAutoApprover::isDeterministicCheck($c->sources))?->key;
        $this->assertNotNull($detKey);
        $this->assertNotNull($nonDetKey);

        $safe = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->create(['check_ids' => [$detKey]]);
        $judgement = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->create(['check_ids' => [$nonDetKey]]);
        $toVerify = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->needsVerification()->create(['check_ids' => [$detKey]]);

        $result = $this->mut->approveAllSafe($audit);

        $this->assertSame(['approved' => 1, 'skipped' => 2], $result);
        $this->assertSame('APROBAT', $safe->fresh()->validation);
        $this->assertTrue($safe->fresh()->auto_approved);
        $this->assertSame('DRAFT_AI', $judgement->fresh()->validation);
        $this->assertSame('DRAFT_AI', $toVerify->fresh()->validation);
    }

    public function test_editing_a_needs_verification_card_requires_confirmation(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $key = $this->firstCheckKey();
        $this->markGap($audit, $key);
        $card = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->needsVerification()->create(['check_ids' => [$key]]);

        $blocked = $this->mut->upsertRecommendation($audit, $card->id, [
            'title' => 'Editat', 'impact' => 'mare', 'checkIds' => [$key],
        ]);
        $this->assertArrayHasKey('error', $blocked);

        $ok = $this->mut->upsertRecommendation($audit, $card->id, [
            'title' => 'Editat', 'impact' => 'mare', 'checkIds' => [$key], 'evidenceConfirmed' => true,
        ]);
        $this->assertArrayHasKey('cardId', $ok);
        $card->refresh();
        $this->assertFalse($card->needs_verification);
        $this->assertSame('EDITAT', $card->validation);
    }
}
