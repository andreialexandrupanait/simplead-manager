<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\NotificationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings is six tabs, not ten.
 *
 * The old set was carved up by whichever component owned the fields rather
 * than by what you came to do: "Email" held nothing but SMTP, "WordPress" was
 * one button, and "Application Backup" and "Data Retention" answered two
 * halves of the same question. Each retired tab keeps its URL as a redirect.
 */
class SettingsStructureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // Within the 2FA grace window: admin, but not bounced to the
        // enrollment challenge before the page under test renders.
        return User::factory()->admin()->create([
            'two_factor_grace_started_at' => now(),
        ]);
    }

    public function test_an_admin_sees_six_tabs(): void
    {
        $this->actingAs($this->admin());

        $html = Blade::render('<x-settings.nav />');

        foreach (['settings.general', 'settings.notifications', 'settings.integrations',
            'settings.data', 'settings.report-templates', 'settings.account'] as $name) {
            $this->assertStringContainsString(route($name), $html, "{$name} should be a tab");
        }

        // The four that were folded in are no longer destinations of their own.
        foreach (['settings.email', 'settings.wordpress', 'settings.data-retention',
            'settings.application-backup', 'settings.profile', 'settings.two-factor'] as $name) {
            $this->assertStringNotContainsString(route($name), $html, "{$name} should not be a tab");
        }
    }

    public function test_a_non_admin_sees_only_the_account_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        $html = Blade::render('<x-settings.nav />');

        $this->assertStringContainsString(route('settings.account'), $html);

        // Not route('settings.general') — that is `/settings`, a prefix of every
        // other settings URL, so it matches even when it is absent.
        $this->assertStringNotContainsString(route('settings.integrations'), $html);
        $this->assertStringNotContainsString(route('settings.data'), $html);
    }

    /** @dataProvider retiredTabs */
    public function test_a_retired_tab_url_still_lands_somewhere(string $from, string $to): void
    {
        $this->actingAs($this->admin())->get($from)->assertRedirect($to);
    }

    public static function retiredTabs(): array
    {
        return [
            'email → notifications' => ['/settings/email', '/settings/notifications'],
            'wordpress → general' => ['/settings/wordpress', '/settings'],
            'retention → data' => ['/settings/data-retention', '/settings/data'],
            'app backup → data' => ['/settings/application-backup', '/settings/data'],
            'profile → account' => ['/settings/profile', '/settings/account'],
            'two-factor → account' => ['/settings/two-factor', '/settings/account'],
        ];
    }

    /** @dataProvider tabPages */
    public function test_each_tab_renders(string $url): void
    {
        $this->withoutVite();

        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public static function tabPages(): array
    {
        return [
            ['/settings'],
            ['/settings/notifications'],
            ['/settings/integrations'],
            ['/settings/data'],
            ['/settings/report-templates'],
            ['/settings/account'],
        ];
    }

    public function test_a_client_cannot_reach_the_admin_tabs(): void
    {
        $this->withoutVite();
        $client = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($client)->get('/settings')->assertForbidden();
        $this->actingAs($client)->get('/settings/account')->assertOk();
    }

    public function test_the_ssl_toggle_is_a_real_property_and_persists(): void
    {
        $this->actingAs($this->admin());

        // The checkbox was bound to a property that did not exist — clicking it
        // threw rather than saving anything.
        Livewire::test(NotificationSettings::class)
            ->set('notifySslExpiring', false)
            ->call('savePreferences')
            ->assertHasNoErrors();

        $this->assertFalse((bool) app(\App\Services\SettingsService::class)->get('notify_ssl_expiring'));
    }
}
