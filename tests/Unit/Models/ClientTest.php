<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_sites_relationship(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        Site::factory()->for($client)->for($user)->create();

        $this->assertCount(1, $client->sites);
    }

    #[Test]
    public function scope_active_filters_active_clients(): void
    {
        Client::factory()->active()->create();
        Client::factory()->active()->create();
        Client::factory()->inactive()->create();

        $this->assertCount(2, Client::active()->get());
    }

    #[Test]
    public function scope_search_filters_by_name_email_company_phone(): void
    {
        Client::factory()->create(['name' => 'John Doe', 'company' => 'Acme Inc']);
        Client::factory()->create(['name' => 'Jane Smith', 'company' => 'Widgets Co']);

        $this->assertCount(1, Client::search('John')->get());
        $this->assertCount(1, Client::search('Widgets')->get());
        $this->assertCount(0, Client::search('nonexistent')->get());
    }

    #[Test]
    public function scope_search_returns_all_when_empty(): void
    {
        Client::factory()->count(3)->create();

        $this->assertCount(3, Client::search(null)->get());
        $this->assertCount(3, Client::search('')->get());
    }

    #[Test]
    public function display_name_returns_company_when_available(): void
    {
        $client = Client::factory()->create(['company' => 'Acme Inc', 'name' => 'John Doe']);

        $this->assertSame('Acme Inc', $client->display_name);
    }

    #[Test]
    public function display_name_returns_name_when_no_company(): void
    {
        $client = Client::factory()->create(['company' => null, 'name' => 'John Doe']);

        $this->assertSame('John Doe', $client->display_name);
    }

    #[Test]
    public function initials_from_company_name(): void
    {
        $client = Client::factory()->create(['company' => 'Acme Corp']);

        $this->assertSame('AC', $client->initials);
    }

    #[Test]
    public function initials_from_single_word_company(): void
    {
        $client = Client::factory()->create(['company' => 'Google']);

        $this->assertSame('GO', $client->initials);
    }

    #[Test]
    public function soft_deletes_work(): void
    {
        $client = Client::factory()->create();
        $client->delete();

        $this->assertSoftDeleted($client);
        $this->assertCount(0, Client::all());
        $this->assertCount(1, Client::withTrashed()->get());
    }
}
