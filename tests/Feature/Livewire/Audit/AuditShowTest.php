<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Audit;

use App\Enums\CheckState;
use App\Enums\UserRole;
use App\Jobs\Audit\RunSfCrawl;
use App\Livewire\Audit\AuditShow;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Models\AuditRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AuditShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_audit_and_its_url(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $audit = Audit::factory()->create();

        Livewire::actingAs($user)
            ->test(AuditShow::class, ['audit' => $audit])
            ->assertOk()
            ->assertSee($audit->url);
    }

    public function test_start_crawl_dispatches_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $audit = Audit::factory()->create();

        Livewire::actingAs($user)
            ->test(AuditShow::class, ['audit' => $audit])
            ->call('startCrawl')
            ->assertDispatched('notify');

        Queue::assertPushed(RunSfCrawl::class, fn (RunSfCrawl $job): bool => $job->auditId === $audit->id);
    }

    public function test_start_crawl_is_blocked_while_a_run_is_active(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $audit = Audit::factory()->create();
        AuditRun::factory()->for($audit)->running()->create();

        Livewire::actingAs($user)
            ->test(AuditShow::class, ['audit' => $audit])
            ->call('startCrawl');

        Queue::assertNotPushed(RunSfCrawl::class);
    }

    public function test_viewers_cannot_start_a_crawl(): void
    {
        Queue::fake();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = Audit::factory()->create();

        Livewire::actingAs($viewer)
            ->test(AuditShow::class, ['audit' => $audit])
            ->call('startCrawl')
            ->assertForbidden();

        Queue::assertNotPushed(RunSfCrawl::class);
    }

    public function test_it_summarizes_result_counts(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $audit = Audit::factory()->create();
        $checks = AuditCheck::query()->limit(4)->pluck('id')->all();

        AuditCheckResult::factory()->for($audit)->withState(CheckState::Exista)->create(['audit_check_id' => $checks[0]]);
        AuditCheckResult::factory()->for($audit)->withState(CheckState::NuExista)->create(['audit_check_id' => $checks[1]]);
        AuditCheckResult::factory()->for($audit)->withState(CheckState::NuSeAplica)->create(['audit_check_id' => $checks[2]]);
        AuditCheckResult::factory()->for($audit)->withState(null)->create(['audit_check_id' => $checks[3]]);

        $counts = Livewire::actingAs($user)
            ->test(AuditShow::class, ['audit' => $audit])
            ->instance()
            ->resultCounts();

        $this->assertSame(
            ['total' => 4, 'exista' => 1, 'nu_exista' => 1, 'nu_se_aplica' => 1, 'manual' => 1],
            $counts,
        );
    }
}
