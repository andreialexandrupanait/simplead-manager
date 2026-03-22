<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    private function createSite(array $attributes = []): Site
    {
        return Site::factory()->for(User::factory())->create($attributes);
    }

    #[Test]
    public function it_can_be_created_via_factory(): void
    {
        $site = $this->createSite();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->for($user)->create();

        $this->assertTrue($site->user->is($user));
    }

    #[Test]
    public function it_can_belong_to_a_client(): void
    {
        $client = Client::factory()->create();
        $site = Site::factory()->forClient($client)->for(User::factory())->create();

        $this->assertTrue($site->client->is($client));
    }

    #[Test]
    public function healthy_sites_have_high_scores(): void
    {
        $this->createSite(['health_score' => 90, 'is_up' => true]);
        $this->createSite(['health_score' => 40, 'is_up' => true]);

        $healthy = Site::where('health_score', '>=', 75)->where('is_up', true)->get();

        $this->assertCount(1, $healthy);
    }

    #[Test]
    public function soft_deletes_work(): void
    {
        $site = $this->createSite();
        $site->delete();

        $this->assertSoftDeleted($site);
        $this->assertCount(0, Site::all());
        $this->assertCount(1, Site::withTrashed()->get());
    }

    #[Test]
    public function it_has_domain_extraction(): void
    {
        $site = $this->createSite(['url' => 'https://example.com/path']);

        $this->assertNotNull($site->domain);
    }

    #[Test]
    public function factory_states_work(): void
    {
        $healthy = Site::factory()->healthy()->for(User::factory())->create();
        $critical = Site::factory()->critical()->for(User::factory())->create();
        $down = Site::factory()->down()->for(User::factory())->create();

        $this->assertGreaterThanOrEqual(80, $healthy->health_score);
        $this->assertTrue($healthy->is_up);
        $this->assertLessThan(50, $critical->health_score);
        $this->assertFalse($down->is_up);
    }
}
