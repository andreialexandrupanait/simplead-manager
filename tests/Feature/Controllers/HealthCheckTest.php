<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database',
                    'redis',
                    'horizon',
                    'disk',
                ],
                'timestamp',
            ]);
    }

    #[Test]
    public function health_endpoint_does_not_require_auth(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
    }

    #[Test]
    public function health_endpoint_returns_database_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertJsonPath('checks.database.status', 'ok');
    }

    #[Test]
    public function health_endpoint_does_not_expose_disk_percent_free(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $disk = $response->json('checks.disk');
        $this->assertArrayNotHasKey('percent_free', $disk);
    }
}
