<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\WordPressApiService;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class WordPressUpdateTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P1-41: update/rollback calls run an unbounded task on the WP host. They
     * must use the long update timeout, not the 30s default that abandons an
     * in-flight update and loses its result.
     */
    public function test_update_and_rollback_calls_use_the_long_update_timeout(): void
    {
        $site = Site::factory()->create();

        $service = new class($site) extends WordPressApiService
        {
            /** @var array<string, int> */
            public array $timeouts = [];

            public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
            {
                $this->timeouts[$endpoint] = $timeout;

                return new Response(new GuzzleResponse(200, [], (string) json_encode([
                    'success' => true,
                    'results' => [],
                ])));
            }
        };

        $service->updatePlugins(['akismet/akismet.php']);
        $service->updateThemes(['twentytwentyfour']);
        $service->updateCore();
        $service->rollback('plugin', 'akismet', '5.0');

        $this->assertGreaterThan(30, WordPressApiService::UPDATE_REQUEST_TIMEOUT);
        $this->assertSame(WordPressApiService::UPDATE_REQUEST_TIMEOUT, $service->timeouts['/plugins/update']);
        $this->assertSame(WordPressApiService::UPDATE_REQUEST_TIMEOUT, $service->timeouts['/themes/update']);
        $this->assertSame(WordPressApiService::UPDATE_REQUEST_TIMEOUT, $service->timeouts['/core/update']);
        $this->assertSame(WordPressApiService::UPDATE_REQUEST_TIMEOUT, $service->timeouts['/rollback/plugin']);
    }
}
