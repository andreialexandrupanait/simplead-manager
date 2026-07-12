<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\DomainStatus;
use App\Jobs\CheckDomainExpiry;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function rdap(string $isoExpiry): array
    {
        return [
            'events' => [['eventAction' => 'expiration', 'eventDate' => $isoExpiry]],
            'entities' => [[
                'roles' => ['registrar'],
                'vcardArray' => ['vcard', [['fn', (object) [], 'text', 'Acme Registrar']]],
            ]],
        ];
    }

    public function test_active_domain_is_recorded_with_registrar(): void
    {
        Queue::fake();
        Http::fake(['rdap.org/domain/acme.com' => Http::response($this->rdap(now()->addYear()->toIso8601String()), 200)]);

        $site = Site::factory()->create(['url' => 'https://acme.com']);

        (new CheckDomainExpiry($site))->handle();

        $fresh = $site->fresh();
        $this->assertSame(DomainStatus::Active, $fresh->domain_status);
        $this->assertNotNull($fresh->domain_expires_at);
        $this->assertSame('Acme Registrar', $fresh->domain_registrar);
        $this->assertNotNull($fresh->domain_checked_at);
    }

    public function test_expiring_soon_is_flagged(): void
    {
        Queue::fake();
        Http::fake(['rdap.org/domain/acme.com' => Http::response($this->rdap(now()->addDays(10)->toIso8601String()), 200)]);

        $site = Site::factory()->create(['url' => 'https://acme.com']);

        (new CheckDomainExpiry($site))->handle();

        $this->assertSame(DomainStatus::ExpiringSoon, $site->fresh()->domain_status);
    }

    public function test_transient_rdap_failure_preserves_last_known_expiry(): void
    {
        // P1-55: a single rdap.org 429 must not overwrite good expiry data with
        // NULLs, and must not stamp domain_checked_at forward (which would block
        // the weekly re-check gate for a full week).
        Queue::fake();
        Http::fake(['rdap.org/domain/acme.com' => Http::response('rate limited', 429)]);

        $priorExpiry = now()->addDays(10)->startOfSecond();
        $checkedAt = now()->subDays(8)->startOfSecond();

        $site = Site::factory()->create([
            'url' => 'https://acme.com',
            'domain_status' => DomainStatus::ExpiringSoon->value,
            'domain_expires_at' => $priorExpiry,
            'domain_registrar' => 'Acme Registrar',
            'domain_checked_at' => $checkedAt,
            'domain_last_error' => null,
        ]);

        (new CheckDomainExpiry($site))->handle();

        $fresh = $site->fresh();
        $this->assertSame(DomainStatus::ExpiringSoon, $fresh->domain_status, 'Status must be preserved.');
        $this->assertSame(
            $priorExpiry->toDateTimeString(),
            $fresh->domain_expires_at->toDateTimeString(),
            'Expiry date must be preserved, not nulled.'
        );
        $this->assertSame('Acme Registrar', $fresh->domain_registrar);
        $this->assertNotNull($fresh->domain_last_error, 'The transient error must be recorded.');
        $this->assertSame(
            $checkedAt->toDateTimeString(),
            $fresh->domain_checked_at->toDateTimeString(),
            'checked_at must not advance so the site is re-checked instead of blocked for a week.'
        );
    }

    public function test_resolves_registrable_domain_from_a_subdomain(): void
    {
        Queue::fake();
        Http::fake([
            'rdap.org/domain/acme.co.uk' => Http::response($this->rdap(now()->addYear()->toIso8601String()), 200),
            'rdap.org/domain/*' => Http::response('', 404),
        ]);

        $site = Site::factory()->create(['url' => 'https://blog.acme.co.uk']);

        (new CheckDomainExpiry($site))->handle();

        $this->assertSame(DomainStatus::Active, $site->fresh()->domain_status);
    }
}
