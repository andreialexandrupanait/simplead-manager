<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\WordPress\WordPressHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressHttpClientGetSigningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret';

    private function makeSite(): Site
    {
        return Site::factory()->create([
            'api_endpoint' => 'https://example.test/wp-json/simplead/v1',
            'api_key' => 'test-key',
            'api_secret' => self::SECRET,
        ]);
    }

    /**
     * Site creation triggers an unrelated favicon fetch; isolate the connector
     * error-logs request we actually care about.
     */
    private function errorLogsRequest(): Request
    {
        $request = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $r): bool => str_contains($r->url(), '/error-logs'));

        $this->assertNotNull($request, 'No /error-logs request was sent.');

        return $request;
    }

    public function test_get_args_travel_as_query_params_not_a_signed_body(): void
    {
        Http::fake([
            '*' => Http::response(['entries' => []], 200),
        ]);

        $client = new WordPressHttpClient($this->makeSite());
        $client->request('GET', '/error-logs', ['limit' => 200]);

        $request = $this->errorLogsRequest();

        // The arg rode the query string...
        $this->assertStringContainsString('limit=200', $request->url());
        // ...and no body was sent (a GET body would never reach the wire).
        $this->assertSame('', $request->body());
        $this->assertSame('GET', $request->method());
    }

    public function test_get_signature_is_computed_over_the_empty_body_that_is_actually_sent(): void
    {
        Http::fake([
            '*' => Http::response(['entries' => []], 200),
        ]);

        $client = new WordPressHttpClient($this->makeSite());
        $client->request('GET', '/error-logs', ['limit' => 200]);

        $request = $this->errorLogsRequest();

        $timestamp = $request->header('X-SAM-Timestamp')[0];
        $nonce = $request->header('X-SAM-Nonce')[0];
        $signature = $request->header('X-SAM-Signature')[0];

        // Recompute exactly as the connector does: METHOD|PATH|TS|NONCE|BODY
        // with an EMPTY body — which is what the fix guarantees is signed.
        $expected = hash_hmac('sha256', implode('|', [
            'GET',
            '/simplead/v1/error-logs',
            $timestamp,
            $nonce,
            '', // empty body
        ]), self::SECRET);

        $this->assertSame($expected, $signature);
    }

    public function test_repeated_invalid_signature_responses_raise_a_fleet_wide_alert(): void
    {
        Cache::flush();

        Http::fake([
            '*' => Http::response(['code' => 'INVALID_SIGNATURE', 'message' => 'bad'], 401),
        ]);

        // Drive enough 401s to cross the fleet-wide threshold (25/hour).
        for ($i = 0; $i < 25; $i++) {
            $client = new WordPressHttpClient($this->makeSite());
            $client->request('GET', '/error-logs', ['limit' => 200]);
        }

        $bucket = 'wp_invalid_signature_'.now()->format('YmdH');
        $this->assertSame(25, (int) Cache::get($bucket));
        $this->assertTrue((bool) Cache::get($bucket.'_alerted'));
    }
}
