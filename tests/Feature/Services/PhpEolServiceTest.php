<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\PhpEolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SPEC §5.3 — "EOL WordPress și PHP". Only the WordPress half existed; a site
 * could run PHP 7.4 (unpatched since 2022) without Manager saying a word.
 *
 * Time is frozen in each test so the assertions describe the classification
 * rules rather than today's date.
 */
class PhpEolServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_branch_past_its_security_date_is_end_of_life(): void
    {
        Carbon::setTestNow('2026-07-30');

        $result = PhpEolService::classify('7.4.33');

        $this->assertSame('eol', $result['status']);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame('7.4', $result['branch']);
    }

    public function test_the_branch_that_expired_last_new_year_is_end_of_life(): void
    {
        // 8.1 lost security support on 2025-12-31 — the fleet's most common branch.
        Carbon::setTestNow('2026-07-30');

        $this->assertSame('eol', PhpEolService::classify('8.1.34')['status']);
    }

    public function test_a_branch_within_six_months_of_its_date_is_approaching(): void
    {
        // 8.2 runs out on 2026-12-31.
        Carbon::setTestNow('2026-08-01');

        $result = PhpEolService::classify('8.2.31');

        $this->assertSame('approaching_eol', $result['status']);
        $this->assertSame('warning', $result['severity']);
    }

    public function test_a_branch_past_active_but_far_from_expiry_is_security_only(): void
    {
        Carbon::setTestNow('2026-07-30');

        // 8.3: active support ended 2025-12-31, security runs to 2027-12-31.
        $this->assertSame('security_only', PhpEolService::classify('8.3.32')['status']);
    }

    public function test_a_current_branch_is_supported(): void
    {
        Carbon::setTestNow('2026-07-30');

        $this->assertSame('supported', PhpEolService::classify('8.5.8')['status']);
    }

    public function test_odd_version_strings_still_resolve_to_a_branch(): void
    {
        // Real values seen in production inventory.
        $this->assertSame('7.4', PhpEolService::branch('7.4.33.12'));
        $this->assertSame('8.3', PhpEolService::branch('8.3.32-1~deb12u1'));
        $this->assertNull(PhpEolService::branch(''));
        $this->assertNull(PhpEolService::branch('unknown'));
    }

    public function test_an_unknown_branch_is_never_reported_as_supported(): void
    {
        $result = PhpEolService::classify('9.9.9');

        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['severity']);
    }

    public function test_checking_a_site_on_an_expired_branch_records_an_alert(): void
    {
        Carbon::setTestNow('2026-07-30');
        $site = Site::factory()->create(['php_version' => '7.4.33']);

        PhpEolService::check($site);

        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $site->user_id]);
    }

    public function test_checking_a_site_on_a_supported_branch_stays_quiet(): void
    {
        Carbon::setTestNow('2026-07-30');
        $site = Site::factory()->create(['php_version' => '8.4.23']);

        PhpEolService::check($site);

        $this->assertDatabaseCount('in_app_notifications', 0);
    }
}
