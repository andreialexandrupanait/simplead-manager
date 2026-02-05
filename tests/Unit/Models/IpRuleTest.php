<?php

namespace Tests\Unit\Models;

use App\Models\IpRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class IpRuleTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_active_scope_excludes_expired_rules(): void
    {
        $site = $this->createSite();

        $activeRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'expires_at' => now()->addDays(7),
        ]);

        $expiredRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'expires_at' => now()->subDay(),
        ]);

        $neverExpiresRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'expires_at' => null,
        ]);

        $activeRules = IpRule::active()->pluck('id')->toArray();

        $this->assertContains($activeRule->id, $activeRules);
        $this->assertContains($neverExpiresRule->id, $activeRules);
        $this->assertNotContains($expiredRule->id, $activeRules);
    }

    public function test_blocked_scope_returns_only_block_type(): void
    {
        $site = $this->createSite();

        $blockRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'type' => 'block',
        ]);

        $allowRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'type' => 'allow',
        ]);

        $blockedRules = IpRule::blocked()->pluck('id')->toArray();

        $this->assertContains($blockRule->id, $blockedRules);
        $this->assertNotContains($allowRule->id, $blockedRules);
    }

    public function test_allowed_scope_returns_only_allow_type(): void
    {
        $site = $this->createSite();

        $blockRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'type' => 'block',
        ]);

        $allowRule = IpRule::factory()->create([
            'site_id' => $site->id,
            'type' => 'allow',
        ]);

        $allowedRules = IpRule::allowed()->pluck('id')->toArray();

        $this->assertContains($allowRule->id, $allowedRules);
        $this->assertNotContains($blockRule->id, $allowedRules);
    }

    public function test_for_site_scope_filters_by_site_id(): void
    {
        $site1 = $this->createSite();
        $site2 = $this->createSite();

        $rule1 = IpRule::factory()->create(['site_id' => $site1->id]);
        $rule2 = IpRule::factory()->create(['site_id' => $site2->id]);

        // Global rule (null site_id)
        $globalRule = IpRule::factory()->create(['site_id' => null]);

        $rulesForSite1 = IpRule::forSite($site1->id)->pluck('id')->toArray();

        $this->assertContains($rule1->id, $rulesForSite1);
        $this->assertContains($globalRule->id, $rulesForSite1); // null site_id included
        $this->assertNotContains($rule2->id, $rulesForSite1);
    }

    public function test_factory_creates_valid_record(): void
    {
        $site = $this->createSite();

        $rule = IpRule::factory()->create(['site_id' => $site->id]);

        $this->assertDatabaseHas('ip_rules', ['id' => $rule->id]);
        $this->assertNotNull($rule->ip_address);
        $this->assertContains($rule->type, ['block', 'allow']);
    }
}
