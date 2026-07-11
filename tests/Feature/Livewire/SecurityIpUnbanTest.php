<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\PushSecuritySettings;
use App\Livewire\Sites\Detail\Security\SecurityIpManagement;
use App\Models\SecurityBannedIp;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * unbanIp used to delete only the local row — the ban stayed live in the
 * WordPress option + brute-force transient and reappeared on the next sync.
 */
class SecurityIpUnbanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Site, 1: User, 2: SecurityBannedIp} */
    private function bannedIpSetup(): array
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);
        $banned = SecurityBannedIp::create([
            'site_id' => $site->id,
            'ip_address' => '203.0.113.7',
            'reason' => 'Brute force',
            'banned_at' => now(),
        ]);

        return [$site, $manager, $banned];
    }

    public function test_unban_reaches_wordpress_then_removes_the_local_row(): void
    {
        $fake = $this->fakeApi();
        $fake->script('unbanIps', [
            'results' => ['unban' => ['success' => true, 'removed' => ['203.0.113.7']]],
            'banned_ips' => [],
        ]);

        [$site, $manager, $banned] = $this->bannedIpSetup();

        Livewire::actingAs($manager)
            ->test(SecurityIpManagement::class, ['site' => $site])
            ->call('unbanIp', $banned->id);

        $this->assertDatabaseMissing('security_banned_ips', ['id' => $banned->id]);
        $this->assertSame([['203.0.113.7']], array_column($fake->callsTo('unbanIps'), 'args')[0]);
    }

    public function test_unban_failure_keeps_the_local_row(): void
    {
        $fake = $this->fakeApi();
        $fake->script('unbanIps', function (): never {
            throw new \RuntimeException('site unreachable');
        });

        [$site, $manager, $banned] = $this->bannedIpSetup();

        Livewire::actingAs($manager)
            ->test(SecurityIpManagement::class, ['site' => $site])
            ->call('unbanIp', $banned->id);

        $this->assertDatabaseHas('security_banned_ips', ['id' => $banned->id]);
    }

    public function test_old_connector_without_unban_support_keeps_the_row_and_warns(): void
    {
        $fake = $this->fakeApi();
        // Old connector: no results.unban block, ban still present
        $fake->script('unbanIps', [
            'results' => [],
            'banned_ips' => ['203.0.113.7' => ['reason' => 'Brute force', 'banned_at' => time()]],
        ]);

        [$site, $manager, $banned] = $this->bannedIpSetup();

        Livewire::actingAs($manager)
            ->test(SecurityIpManagement::class, ['site' => $site])
            ->call('unbanIp', $banned->id);

        $this->assertDatabaseHas('security_banned_ips', ['id' => $banned->id]);
    }

    public function test_push_sync_treats_an_empty_banned_list_as_authoritative(): void
    {
        $fake = $this->fakeApi();
        $fake->script('request', new Response(new Psr7Response(200, [], (string) json_encode([
            'success' => true,
            'results' => ['hardening' => ['success' => true]],
            'banned_ips' => [],
        ]))));

        [$site, , $banned] = $this->bannedIpSetup();
        SecuritySetting::create([
            'site_id' => $site->id,
            'category' => 'hardening',
            'setting_key' => 'hide_wp_version',
            'setting_value' => ['enabled' => true],
            'is_enabled' => true,
        ]);

        (new PushSecuritySettings($site))->handle();

        // The last unbanned IP must disappear locally too — [] means "no bans"
        $this->assertDatabaseMissing('security_banned_ips', ['id' => $banned->id]);
    }
}
