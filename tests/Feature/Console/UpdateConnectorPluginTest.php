<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Site;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class UpdateConnectorPluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_push_persists_the_reported_connector_version(): void
    {
        $fake = $this->fakeApi();
        $fake->script('request', new Response(new Psr7Response(200, [], (string) json_encode([
            'success' => true,
            'old_version' => '2.12.0',
            'new_version' => '2.16.0',
        ]))));

        $site = Site::factory()->create(['is_connected' => true, 'connector_version' => '2.12.0']);

        $this->artisan('connector:update', ['--site' => $site->id])->assertSuccessful();

        $this->assertSame('2.16.0', $site->fresh()->connector_version);
    }
}
