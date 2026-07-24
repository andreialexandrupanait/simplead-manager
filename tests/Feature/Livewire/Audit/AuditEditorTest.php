<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Enums\CheckState;
use App\Enums\UserRole;
use App\Livewire\Audit\AuditEditor;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditEditorTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => UserRole::Manager]);
    }

    /** The first check (by sort_order) — it belongs to the editor's default active section. */
    private function firstCheck(): AuditCheck
    {
        return AuditCheck::query()->orderBy('sort_order')->firstOrFail();
    }

    private function markGap(Audit $audit, AuditCheck $check): void
    {
        AuditCheckResult::query()->create([
            'audit_id' => $audit->id,
            'audit_check_id' => $check->id,
            'state' => CheckState::NuExista,
            'evidence' => [],
            'state_set_by' => 'auto',
        ]);
    }

    public function test_it_renders_the_editor(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);

        Livewire::actingAs($this->manager())
            ->test(AuditEditor::class, ['audit' => $audit])
            ->assertOk()
            ->assertSee('Validare');
    }

    public function test_set_state_persists(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);
        $check = $this->firstCheck();

        Livewire::actingAs($this->manager())
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('setState', $check->key, CheckState::Exista->value);

        $this->assertDatabaseHas('audit_check_results', [
            'audit_id' => $audit->id, 'audit_check_id' => $check->id, 'state' => 'EXISTA', 'state_set_by' => 'manual',
        ]);
    }

    public function test_nu_se_aplica_opens_reason_then_saves(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);
        $check = $this->firstCheck();

        Livewire::actingAs($this->manager())
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('setState', $check->key, CheckState::NuSeAplica->value)
            ->assertSet('reasonForKey', $check->key)
            ->set('reasonText', 'Fără e-commerce.')
            ->call('confirmNuSeAplica')
            ->assertSet('reasonForKey', null);

        $result = AuditCheckResult::query()->where('audit_id', $audit->id)->where('audit_check_id', $check->id)->firstOrFail();
        $this->assertSame(CheckState::NuSeAplica, $result->state);
        $this->assertSame('Fără e-commerce.', $result->evidence['reason']);
    }

    public function test_create_card_on_a_gap(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $check = $this->firstCheck();
        $this->markGap($audit, $check);

        Livewire::actingAs($this->manager())
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('newCard', $check->key)
            ->set('cardTitle', 'Adaugă titluri')
            ->set('cardImpact', 'mare')
            ->set('cardGaps', [$check->key])
            ->call('saveCard')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('audit_cards', [
            'audit_id' => $audit->id, 'title' => 'Adaugă titluri', 'validation' => 'EDITAT',
        ]);
    }

    public function test_approve_and_reject_cards(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare]);
        $check = $this->firstCheck();
        $draft = AuditCard::factory()->for($audit)->validation('DRAFT_AI')->create(['check_ids' => [$check->key]]);

        Livewire::actingAs($this->manager())
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('approveCard', $draft->id);

        $this->assertSame('APROBAT', $draft->fresh()->validation);
    }

    public function test_viewers_cannot_set_state(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = Audit::factory()->create(['status' => AuditStatus::Colectare]);
        $check = $this->firstCheck();

        Livewire::actingAs($viewer)
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('setState', $check->key, CheckState::Exista->value)
            ->assertForbidden();

        $this->assertDatabaseCount('audit_check_results', 0);
    }

    public function test_validated_audit_is_read_only(): void
    {
        $manager = $this->manager();
        $audit = Audit::factory()->create(['status' => AuditStatus::Validat]);
        $check = $this->firstCheck();

        Livewire::actingAs($manager)
            ->test(AuditEditor::class, ['audit' => $audit])
            ->call('setState', $check->key, CheckState::Exista->value)
            ->assertForbidden();
    }
}
