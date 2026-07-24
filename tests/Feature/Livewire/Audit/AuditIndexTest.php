<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Enums\UserRole;
use App\Livewire\Audit\AuditIndex;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_audits_and_filters_by_status(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $configured = Audit::factory()->create(['status' => AuditStatus::Configurat]);
        $published = Audit::factory()->create(['status' => AuditStatus::Publicat]);

        Livewire::actingAs($user)
            ->test(AuditIndex::class)
            ->assertOk()
            ->assertSee($configured->url)
            ->assertSee($published->url)
            ->set('status', AuditStatus::Publicat->value)
            ->assertSee($published->url)
            ->assertDontSee($configured->url);
    }

    public function test_it_shows_the_empty_state_with_no_audits(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);

        Livewire::actingAs($user)
            ->test(AuditIndex::class)
            ->assertSee('Niciun audit');
    }
}
