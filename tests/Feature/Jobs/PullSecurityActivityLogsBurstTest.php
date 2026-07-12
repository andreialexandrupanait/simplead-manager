<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\PullSecurityActivityLogs;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-52: the connector returns at most one page and the manager made one request
 * per 6h sync, so any burst larger than a page permanently lost its older events.
 * The pull now paginates forward from its watermark until the burst is drained.
 * Also covers P1-11: a malformed row in a page must not wedge the whole pull.
 */
class PullSecurityActivityLogsBurstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function jsonResponse(array $body): Response
    {
        return new Response(new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /**
     * Bind an API fake that behaves like an ASC cursor-paginating connector:
     * given `since`, it returns up to 500 rows with created_at > since, ascending.
     *
     * @param  array<int, array<string, mixed>>  $events  ascending by created_at
     */
    private function bindPaginatingApi(array $events): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturnCallback(function ($method, $endpoint, $data, $query) use ($events) {
            $since = $query['since'] ?? null;
            $limit = $query['limit'] ?? 500;

            $page = array_values(array_filter(
                $events,
                fn ($e) => $since === null || $e['created_at'] > $since,
            ));

            return $this->jsonResponse(['logs' => array_slice($page, 0, $limit)]);
        });

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_burst_larger_than_one_page_is_fully_ingested(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $base = Carbon::parse('2026-07-05 00:00:00');
        $events = [];
        for ($i = 0; $i < 1200; $i++) {
            $events[] = [
                'action' => 'user_login',
                'user_login' => 'user'.$i,
                'user_ip' => '203.0.113.1',
                'created_at' => $base->copy()->addSeconds($i)->format('Y-m-d H:i:s'),
            ];
        }

        $this->bindPaginatingApi($events);

        (new PullSecurityActivityLogs($site))->handle();

        // All 1200 events across 3 pages (500 + 500 + 200) must be captured —
        // the old newest-500 single request would have kept only 500.
        $this->assertDatabaseCount('security_activity_logs', 1200);
    }

    public function test_a_second_run_does_not_re_ingest_already_stored_events(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $base = Carbon::parse('2026-07-05 00:00:00');
        $events = [];
        for ($i = 0; $i < 700; $i++) {
            $events[] = [
                'action' => 'user_login',
                'user_login' => 'user'.$i,
                'user_ip' => '203.0.113.1',
                'created_at' => $base->copy()->addSeconds($i)->format('Y-m-d H:i:s'),
            ];
        }

        $this->bindPaginatingApi($events);

        (new PullSecurityActivityLogs($site))->handle();
        (new PullSecurityActivityLogs($site))->handle();

        $this->assertDatabaseCount('security_activity_logs', 700);
    }

    public function test_malformed_row_in_a_page_does_not_wedge_the_pull(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $this->bindPaginatingApi([
            ['action' => 'user_login', 'user_ip' => '198.51.100.9', 'created_at' => '2026-07-05 10:00:00'],
            ['action' => 'user_login', 'user_ip' => 'garbage-ip', 'created_at' => '2026-07-05 10:00:01'],
            ['action' => 'plugin_activated', 'user_ip' => '198.51.100.9', 'created_at' => '2026-07-05 10:00:02'],
        ]);

        (new PullSecurityActivityLogs($site))->handle();

        // The bad-IP row is sanitized (IP nulled) rather than aborting the batch,
        // so every event is ingested and the watermark advances normally.
        $this->assertDatabaseCount('security_activity_logs', 3);
        $this->assertDatabaseHas('security_activity_logs', ['site_id' => $site->id, 'ip_address' => null]);
    }
}
