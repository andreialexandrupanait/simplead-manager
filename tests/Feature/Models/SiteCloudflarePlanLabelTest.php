<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\SiteCloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3-31: plan_label used ucfirst($this->plan_type) directly, which is a TypeError
 * under strict types when plan_type is null (a freshly connected zone). It must
 * degrade to a null/safe label instead.
 */
class SiteCloudflarePlanLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_label_is_null_safe_when_plan_type_is_null(): void
    {
        $cf = SiteCloudflare::factory()->create(['plan_type' => null]);

        // No TypeError; a null plan yields no label.
        $this->assertNull($cf->plan_label);
    }

    public function test_plan_label_capitalizes_a_present_plan(): void
    {
        $cf = SiteCloudflare::factory()->create(['plan_type' => 'pro']);

        $this->assertSame('Pro', $cf->plan_label);
    }
}
