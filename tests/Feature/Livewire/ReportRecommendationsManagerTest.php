<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\ReportRecommendationsManager;
use App\Models\RecommendationTemplate;
use App\Models\ReportRecommendation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportRecommendationsManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_recommendations_manager(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── addCustomRecommendation() ────────────────────────────────────

    #[Test]
    public function user_can_add_custom_recommendation(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('newRecTitle', 'Enable caching')
            ->set('newRecDescription', 'Use a caching plugin to speed up the site.')
            ->set('newRecPriority', 'high')
            ->set('newRecCategory', 'performance')
            ->call('addCustomRecommendation')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('report_recommendations', [
            'site_id' => $this->site->id,
            'title' => 'Enable caching',
            'priority' => 'high',
            'category' => 'performance',
            'is_included' => true,
            'is_auto_generated' => false,
        ]);
    }

    #[Test]
    public function adding_recommendation_resets_form_fields(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('newRecTitle', 'Update plugins')
            ->set('newRecDescription', 'Keep plugins up to date.')
            ->set('newRecPriority', 'high')
            ->set('newRecCategory', 'technical')
            ->call('addCustomRecommendation');

        $component->assertSet('newRecTitle', '');
        $component->assertSet('newRecDescription', '');
        $component->assertSet('newRecPriority', 'medium');
    }

    // ─── updateRec() ──────────────────────────────────────────────────

    #[Test]
    public function user_can_update_recommendation_title(): void
    {
        $rec = ReportRecommendation::create([
            'site_id' => $this->site->id,
            'title' => 'Old Title',
            'description' => 'A description.',
            'priority' => 'medium',
            'category' => 'technical',
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('updateRec', $rec->id, 'title', 'New Title');

        $this->assertDatabaseHas('report_recommendations', [
            'id' => $rec->id,
            'title' => 'New Title',
        ]);
    }

    #[Test]
    public function user_can_update_recommendation_priority(): void
    {
        $rec = ReportRecommendation::create([
            'site_id' => $this->site->id,
            'title' => 'Fix SSL',
            'description' => 'Install SSL certificate.',
            'priority' => 'low',
            'category' => 'technical',
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('updateRec', $rec->id, 'priority', 'high');

        $this->assertDatabaseHas('report_recommendations', [
            'id' => $rec->id,
            'priority' => 'high',
        ]);
    }

    // ─── toggleIncluded() ─────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_recommendation_included_flag(): void
    {
        $rec = ReportRecommendation::create([
            'site_id' => $this->site->id,
            'title' => 'Toggle me',
            'description' => 'Description.',
            'priority' => 'medium',
            'category' => 'technical',
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('toggleIncluded', $rec->id);

        $this->assertDatabaseHas('report_recommendations', [
            'id' => $rec->id,
            'is_included' => false,
        ]);

        // Toggle back
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('toggleIncluded', $rec->id);

        $this->assertDatabaseHas('report_recommendations', [
            'id' => $rec->id,
            'is_included' => true,
        ]);
    }

    // ─── removeRecommendation() ───────────────────────────────────────

    #[Test]
    public function user_can_remove_recommendation(): void
    {
        $rec = ReportRecommendation::create([
            'site_id' => $this->site->id,
            'title' => 'Remove me',
            'description' => 'Description.',
            'priority' => 'low',
            'category' => 'seo',
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('removeRecommendation', $rec->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('report_recommendations', ['id' => $rec->id]);
    }

    // ─── saveAsTemplate() ─────────────────────────────────────────────

    #[Test]
    public function user_can_save_recommendations_as_template(): void
    {
        ReportRecommendation::create([
            'site_id' => $this->site->id,
            'title' => 'Enable backups',
            'description' => 'Schedule daily backups.',
            'priority' => 'high',
            'category' => 'technical',
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('templateName', 'My Standard Template')
            ->call('saveAsTemplate')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('recommendation_templates', [
            'user_id' => $this->admin->id,
            'name' => 'My Standard Template',
        ]);
    }

    #[Test]
    public function save_as_template_requires_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('templateName', '')
            ->call('saveAsTemplate')
            ->assertHasErrors(['templateName' => 'required']);
    }

    // ─── deleteTemplate() ─────────────────────────────────────────────

    #[Test]
    public function user_can_delete_their_own_template(): void
    {
        $template = RecommendationTemplate::create([
            'user_id' => $this->admin->id,
            'name' => 'To Delete',
            'recommendations' => [],
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->call('deleteTemplate', $template->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('recommendation_templates', ['id' => $template->id]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function add_custom_recommendation_requires_title(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('newRecTitle', '')
            ->set('newRecDescription', 'Some description.')
            ->call('addCustomRecommendation')
            ->assertHasErrors(['newRecTitle' => 'required']);
    }

    #[Test]
    public function add_custom_recommendation_requires_valid_priority(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportRecommendationsManager::class, ['site' => $this->site])
            ->set('newRecTitle', 'Valid Title')
            ->set('newRecDescription', 'Valid description.')
            ->set('newRecPriority', 'urgent') // invalid
            ->call('addCustomRecommendation')
            ->assertHasErrors(['newRecPriority']);
    }
}
