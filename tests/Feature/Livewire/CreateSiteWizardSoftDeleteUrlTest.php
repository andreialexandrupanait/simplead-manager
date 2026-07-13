<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\CreateSiteWizard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-12: a URL whose ONLY conflict is a soft-deleted site must be re-addable.
 * The unique rule used to count soft-deleted rows, so a removed site's URL
 * could never be added again.
 */
class CreateSiteWizardSoftDeleteUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::actingAs(User::factory()->create());
    }

    public function test_url_matching_only_a_soft_deleted_site_can_be_re_added(): void
    {
        $site = Site::factory()->create(['url' => 'https://gone.example']);
        $site->delete(); // soft delete

        Livewire::test(CreateSiteWizard::class)
            ->set('form.url', 'https://gone.example')
            ->set('form.name', 'Gone Again')
            ->call('nextStep')
            ->assertHasNoErrors('form.url');
    }

    public function test_url_matching_a_live_site_is_still_rejected(): void
    {
        Site::factory()->create(['url' => 'https://live.example']);

        Livewire::test(CreateSiteWizard::class)
            ->set('form.url', 'https://live.example')
            ->set('form.name', 'Duplicate')
            ->call('nextStep')
            ->assertHasErrors('form.url');
    }
}
