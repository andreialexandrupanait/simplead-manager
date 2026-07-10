<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\ManageSiteTags;
use App\Livewire\Sites\SitesList;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_and_assign_a_tag(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);

        Livewire::actingAs($manager)
            ->test(ManageSiteTags::class, ['site' => $site])
            ->set('newTagName', 'staging')
            ->set('newTagColor', 'amber')
            ->call('createTag')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tags', ['name' => 'staging', 'color' => 'amber']);
        $this->assertTrue($site->fresh()->tags()->where('name', 'staging')->exists());
    }

    public function test_viewer_cannot_toggle_a_tag(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);
        $tag = Tag::factory()->create();

        Livewire::actingAs($viewer)
            ->test(ManageSiteTags::class, ['site' => $site])
            ->call('toggleTag', $tag->id)
            ->assertForbidden();

        $this->assertDatabaseCount('site_tag', 0);
    }

    public function test_sites_list_filters_by_tag(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $tagged = Site::factory()->create(['name' => 'Tagged Site']);
        $untagged = Site::factory()->create(['name' => 'Other Site']);
        $tag = Tag::factory()->create();
        $tagged->tags()->attach($tag->id);

        Livewire::actingAs($admin)
            ->test(SitesList::class)
            ->set('tagId', $tag->id)
            ->assertViewHas('sites', function ($sites) use ($tagged, $untagged) {
                $ids = $sites->pluck('id')->all();

                return in_array($tagged->id, $ids, true) && ! in_array($untagged->id, $ids, true);
            });
    }
}
