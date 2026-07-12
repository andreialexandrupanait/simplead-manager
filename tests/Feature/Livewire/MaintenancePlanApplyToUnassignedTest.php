<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\ApplyPlanToSite;
use App\Livewire\MaintenancePlans;
use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-38: "Apply to Unassigned" must use the plan explicitly marked default —
 * never an arbitrary plan. With no default set it must be a clear no-op.
 */
class MaintenancePlanApplyToUnassignedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_it_applies_the_designated_default_plan_to_unassigned_sites(): void
    {
        Queue::fake();

        // Create the site BEFORE any default plan exists, so the Site::created
        // hook does not pre-dispatch the (unique) apply job and mask this one.
        $site = Site::factory()->create(['is_connected' => true, 'maintenance_plan_id' => null]);

        $default = MaintenancePlan::create(['name' => 'Default', 'is_default' => true, 'include_modules' => true]);
        // A non-default plan that sorts first — the old bug would pick this one.
        MaintenancePlan::create(['name' => 'Arbitrary', 'is_default' => false, 'sort_order' => -10, 'include_modules' => true]);

        Livewire::actingAs($this->admin())
            ->test(MaintenancePlans::class)
            ->call('applyPlanToAll');

        Queue::assertPushed(
            ApplyPlanToSite::class,
            fn (ApplyPlanToSite $job) => $job->plan->id === $default->id && $job->site->id === $site->id,
        );
    }

    public function test_it_refuses_and_applies_nothing_when_no_default_plan_exists(): void
    {
        Queue::fake();

        MaintenancePlan::create(['name' => 'A', 'is_default' => false, 'include_modules' => true]);
        MaintenancePlan::create(['name' => 'B', 'is_default' => false, 'include_modules' => true]);
        Site::factory()->create(['is_connected' => true, 'maintenance_plan_id' => null]);

        Queue::fake(); // reset

        Livewire::actingAs($this->admin())
            ->test(MaintenancePlans::class)
            ->call('applyPlanToAll')
            ->assertDispatched('notify', fn ($event, $params) => ($params['type'] ?? null) === 'error'
                && str_contains($params['message'] ?? '', 'No default plan set'));

        Queue::assertNotPushed(ApplyPlanToSite::class);
    }
}
