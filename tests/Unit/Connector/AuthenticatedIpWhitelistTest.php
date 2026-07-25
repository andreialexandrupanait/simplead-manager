<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SAM_IP_Whitelist;
use SamConnectorTestStore;
use WP_Error;
use WP_REST_Request;

/**
 * Connector 2.19.0 — the permission chain is rate limit → HMAC → record IP.
 *
 * Until 2.18.0 the IP whitelist was evaluated BEFORE authentication, and /info
 * auto-added the calling IP. Every synced site therefore held a one-entry whitelist
 * containing the manager's address, so moving the manager to a new IP produced
 * 403 IP_NOT_WHITELISTED on every route — including the /info route that was
 * supposed to bootstrap the new address. These tests pin the new contract:
 *
 *   - an unauthenticated request is refused AND cannot touch the whitelist;
 *   - an authenticated request is allowed and has its IP recorded, additively.
 *
 * No real IP is hardcoded here beyond RFC 5737 / RFC 3849 documentation ranges —
 * the fix must work for any future legitimate manager IP change.
 */
final class AuthenticatedIpWhitelistTest extends TestCase
{
    private const API_KEY = 'test-api-key';

    private const API_SECRET = 'test-api-secret';

    private const OPTION = 'sam_ip_whitelist';

    private const ROUTE = '/simplead/v1/info';

    /** An address already trusted before the migration. */
    private const KNOWN_IP = '203.0.113.10';

    /** The address the manager moves to. */
    private const NEW_IP = '198.51.100.20';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__.'/../../Fakes/wordpress-connector-stubs.php';

        $plugin = __DIR__.'/../../../wordpress-plugin/simplead-manager-connector/includes/';

        require_once $plugin.'class-ip-whitelist.php';
        require_once $plugin.'class-authentication.php';
        require_once $plugin.'class-rate-limiter.php';
        require_once $plugin.'class-request-logger.php';
        require_once $plugin.'class-rest-api.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        SamConnectorTestStore::reset();
        SamConnectorTestStore::$options['sam_api_key'] = self::API_KEY;
        SamConnectorTestStore::$options['sam_api_secret'] = self::API_SECRET;

        $this->arriveFrom(self::NEW_IP);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);

        SamConnectorTestStore::reset();

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function arriveFrom(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    private function seedWhitelist(array $entries): void
    {
        SamConnectorTestStore::$options[self::OPTION] = $entries;
        SamConnectorTestStore::$updateOptionCalls[self::OPTION] = 0;
    }

    private function storedWhitelist(): array
    {
        return SamConnectorTestStore::$options[self::OPTION] ?? [];
    }

    private function whitelistWrites(): int
    {
        return SamConnectorTestStore::updateCalls(self::OPTION);
    }

    /**
     * Build a request signed exactly the way the manager signs it:
     * METHOD|PATH|TIMESTAMP|NONCE|BODY, HMAC-SHA256 with the site's secret.
     *
     * @param  array<string, string|null>  $overrides
     */
    private function signedRequest(array $overrides = [], string $method = 'GET', string $body = ''): WP_REST_Request
    {
        $timestamp = $overrides['X-SAM-Timestamp'] ?? (string) time();
        $nonce = $overrides['X-SAM-Nonce'] ?? bin2hex(random_bytes(16));
        $secret = $overrides['__secret'] ?? self::API_SECRET;

        $signature = hash_hmac(
            'sha256',
            implode('|', [strtoupper($method), self::ROUTE, $timestamp, $nonce, $body]),
            $secret,
        );

        $headers = [
            'X-SAM-Key' => self::API_KEY,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Nonce' => $nonce,
            'X-SAM-Signature' => $overrides['X-SAM-Signature'] ?? $signature,
        ];

        foreach ($overrides as $name => $value) {
            if ($name === '__secret') {
                continue;
            }

            if ($value === null) {
                unset($headers[$name]);

                continue;
            }

            $headers[$name] = $value;
        }

        return new WP_REST_Request($method, self::ROUTE, $body, $headers);
    }

    /**
     * @return true|WP_Error
     */
    private function permit(WP_REST_Request $request)
    {
        return (new ConnectorTestEndpoint)->check_permission($request);
    }

    // ── 1. Invalid HMAC + unknown IP ────────────────────────────────────────

    public function test_invalid_signature_from_unknown_ip_is_refused_and_does_not_touch_the_whitelist(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['X-SAM-Signature' => str_repeat('a', 64)]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('INVALID_SIGNATURE', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertNotContains(self::NEW_IP, $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    public function test_signature_from_a_wrong_secret_is_refused_and_does_not_touch_the_whitelist(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['__secret' => 'attacker-guess']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('INVALID_SIGNATURE', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    public function test_wrong_api_key_is_refused_and_does_not_touch_the_whitelist(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['X-SAM-Key' => 'not-the-key']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('INVALID_API_KEY', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 2. Missing HMAC + unknown IP ────────────────────────────────────────

    public function test_request_without_auth_headers_is_refused_and_whitelist_is_unchanged(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit(new WP_REST_Request('GET', self::ROUTE));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('MISSING_AUTH_HEADERS', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    public function test_request_missing_only_the_signature_is_refused_and_whitelist_is_unchanged(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['X-SAM-Signature' => null]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('MISSING_AUTH_HEADERS', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 3. Valid HMAC + IP already whitelisted ──────────────────────────────

    public function test_authenticated_request_from_an_already_listed_ip_is_allowed_without_rewriting_the_option(): void
    {
        $this->arriveFrom(self::KNOWN_IP);
        $this->seedWhitelist([self::KNOWN_IP, '192.0.2.0/24']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame([self::KNOWN_IP, '192.0.2.0/24'], $this->storedWhitelist());
        $this->assertSame(1, count(array_keys($this->storedWhitelist(), self::KNOWN_IP, true)));
        $this->assertSame(0, $this->whitelistWrites(), 'update_option() must not run when nothing changes');
    }

    public function test_an_ip_already_covered_by_a_cidr_entry_is_not_appended(): void
    {
        $this->arriveFrom('192.0.2.55');
        $this->seedWhitelist(['192.0.2.0/24']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame(['192.0.2.0/24'], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 4. Valid HMAC + new IP ──────────────────────────────────────────────

    public function test_authenticated_request_from_a_new_ip_is_allowed_and_recorded_without_losing_existing_entries(): void
    {
        $this->arriveFrom(self::NEW_IP);
        $this->seedWhitelist([self::KNOWN_IP, '192.0.2.0/24']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame([self::KNOWN_IP, '192.0.2.0/24', self::NEW_IP], $this->storedWhitelist());
        $this->assertContains(self::KNOWN_IP, $this->storedWhitelist(), 'the previous manager IP must survive for rollback');
        $this->assertContains('192.0.2.0/24', $this->storedWhitelist(), 'operator-managed CIDR ranges must survive');
        $this->assertSame(1, $this->whitelistWrites());
    }

    // ── 5. Valid HMAC + empty whitelist ─────────────────────────────────────

    public function test_authenticated_request_against_an_empty_whitelist_is_allowed_and_seeds_the_list(): void
    {
        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame([self::NEW_IP], $this->storedWhitelist());
        $this->assertSame(1, $this->whitelistWrites());
    }

    // ── 6. Invalid IP ───────────────────────────────────────────────────────

    public function test_an_unresolvable_client_ip_is_not_recorded_but_the_authenticated_request_still_passes(): void
    {
        // No usable proxy header and no REMOTE_ADDR — get_client_ip() returns 0.0.0.0.
        unset($_SERVER['REMOTE_ADDR']);
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result, 'a valid HMAC must not be refused because the IP could not be resolved');
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertNotContains('0.0.0.0', $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidAddressProvider(): array
    {
        return [
            ['not-an-ip'],
            [''],
            ['   '],
            ['999.999.999.999'],
            ['192.0.2.0/24'],
            ['0.0.0.0'],
            ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('invalidAddressProvider')]
    public function test_allow_authenticated_ip_rejects_malformed_addresses_without_writing(string $candidate): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = SAM_IP_Whitelist::allow_authenticated_ip($candidate);

        $this->assertSame(SAM_IP_Whitelist::RESULT_INVALID_IP, $result);
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 7. Expired timestamp ────────────────────────────────────────────────

    public function test_expired_timestamp_is_refused_and_the_ip_is_not_recorded(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['X-SAM-Timestamp' => (string) (time() - 3600)]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('EXPIRED_REQUEST', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertNotContains(self::NEW_IP, $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    public function test_far_future_timestamp_is_refused_and_the_ip_is_not_recorded(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $result = $this->permit($this->signedRequest(['X-SAM-Timestamp' => (string) (time() + 3600)]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('EXPIRED_REQUEST', $result->get_error_code());
        $this->assertSame([self::KNOWN_IP], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 8. Replayed nonce ───────────────────────────────────────────────────

    public function test_a_replayed_nonce_is_refused_and_does_not_record_the_ip(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        $nonce = bin2hex(random_bytes(16));

        // First use succeeds — and, being authentic, records the new IP.
        $this->assertTrue($this->permit($this->signedRequest(['X-SAM-Nonce' => $nonce])));
        $this->assertSame([self::KNOWN_IP, self::NEW_IP], $this->storedWhitelist());
        $this->assertSame(1, $this->whitelistWrites());

        // A replay of the exact same signed request from a different address must fail
        // and must not add that address.
        $this->arriveFrom('198.51.100.99');
        $replay = $this->permit($this->signedRequest(['X-SAM-Nonce' => $nonce]));

        $this->assertInstanceOf(WP_Error::class, $replay);
        $this->assertSame('NONCE_REUSED', $replay->get_error_code());
        $this->assertNotContains('198.51.100.99', $this->storedWhitelist());
        $this->assertSame(1, $this->whitelistWrites(), 'the replay must not write the option again');
    }

    // ── 9. IPv6 ─────────────────────────────────────────────────────────────

    public function test_an_ipv6_manager_address_is_recorded_without_corrupting_the_list(): void
    {
        $this->arriveFrom('2001:db8::1');
        $this->seedWhitelist([self::KNOWN_IP, '192.0.2.0/24']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame([self::KNOWN_IP, '192.0.2.0/24', '2001:db8::1'], $this->storedWhitelist());
        $this->assertSame(1, $this->whitelistWrites());
    }

    public function test_a_different_textual_form_of_a_listed_ipv6_address_is_not_duplicated(): void
    {
        $this->arriveFrom('2001:0DB8:0000:0000:0000:0000:0000:0001');
        $this->seedWhitelist(['2001:db8::1']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame(['2001:db8::1'], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    public function test_an_ipv6_address_covered_by_an_ipv6_cidr_entry_is_not_appended(): void
    {
        $this->arriveFrom('2001:db8:0:0:0:0:0:abcd');
        $this->seedWhitelist(['2001:db8::/32']);

        $result = $this->permit($this->signedRequest());

        $this->assertTrue($result);
        $this->assertSame(['2001:db8::/32'], $this->storedWhitelist());
        $this->assertSame(0, $this->whitelistWrites());
    }

    // ── 10. Repeated requests from the same IP ──────────────────────────────

    public function test_consecutive_authenticated_requests_from_the_same_ip_record_it_exactly_once(): void
    {
        $this->seedWhitelist([self::KNOWN_IP]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->permit($this->signedRequest()));
        }

        $this->assertSame([self::KNOWN_IP, self::NEW_IP], $this->storedWhitelist());
        $this->assertSame(1, count(array_keys($this->storedWhitelist(), self::NEW_IP, true)));
        $this->assertSame(1, $this->whitelistWrites(), 'only the first request may write the option');
    }

    // ── Housekeeping guarantees ─────────────────────────────────────────────

    public function test_normalisation_drops_blank_entries_without_dropping_real_ones(): void
    {
        $this->arriveFrom(self::NEW_IP);
        $this->seedWhitelist([self::KNOWN_IP, '  ', '', '  192.0.2.0/24  ']);

        $this->assertTrue($this->permit($this->signedRequest()));

        $this->assertSame([self::KNOWN_IP, '192.0.2.0/24', self::NEW_IP], $this->storedWhitelist());
        $this->assertSame(1, $this->whitelistWrites());
    }

    public function test_allow_authenticated_ip_reports_its_outcome(): void
    {
        $this->seedWhitelist([]);

        $this->assertSame(SAM_IP_Whitelist::RESULT_ADDED, SAM_IP_Whitelist::allow_authenticated_ip(self::NEW_IP));
        $this->assertSame(SAM_IP_Whitelist::RESULT_ALREADY_ALLOWED, SAM_IP_Whitelist::allow_authenticated_ip(self::NEW_IP));
        $this->assertSame(SAM_IP_Whitelist::RESULT_INVALID_IP, SAM_IP_Whitelist::allow_authenticated_ip('nope'));
    }

    public function test_a_corrupt_option_value_cannot_break_the_permission_chain(): void
    {
        SamConnectorTestStore::$options[self::OPTION] = [self::KNOWN_IP, ['nested'], null, 42];
        SamConnectorTestStore::$updateOptionCalls[self::OPTION] = 0;

        $this->assertTrue($this->permit($this->signedRequest()));

        $this->assertSame([self::KNOWN_IP, '42', self::NEW_IP], $this->storedWhitelist());
    }

    public function test_the_raw_whitelist_check_still_works_for_callers_that_want_it(): void
    {
        $this->arriveFrom(self::NEW_IP);

        $this->seedWhitelist([]);
        $this->assertTrue(SAM_IP_Whitelist::check(), 'an empty list still means allow-all');

        $this->seedWhitelist([self::KNOWN_IP]);
        $this->assertInstanceOf(WP_Error::class, SAM_IP_Whitelist::check());

        $this->seedWhitelist([self::KNOWN_IP, self::NEW_IP]);
        $this->assertTrue(SAM_IP_Whitelist::check());
    }
}
