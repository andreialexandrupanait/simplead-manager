<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where account controls live, and where they do not.
 *
 * Profile and Log Out belong to the header's account menu — they are things
 * about you. Settings is a destination like any other nav entry and stays in
 * the sidebar. It was briefly moved into the account menu along with the other
 * two, purely because the three sat next to each other in the rail footer;
 * adjacency is not a category.
 *
 * The rule this pins is "once, not twice": a control that appears in both
 * places is a control whose two copies will drift apart.
 */
class AccountMenuPlacementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * withoutVite: the suite has no built manifest (assets are compiled in the
     * image, not locally), and it also keeps the JS bundle out of the string we
     * are counting occurrences in.
     */
    private function renderShell(User $user): string
    {
        $this->withoutVite();

        return $this->actingAs($user)->get(route('dashboard'))->getContent();
    }

    public function test_settings_appears_exactly_once_for_an_admin(): void
    {
        $html = $this->renderShell(User::factory()->create(['role' => UserRole::Admin]));

        $this->assertSame(
            1,
            substr_count($html, 'href="'.route('settings.general').'"'),
            'Settings must be linked once — the sidebar footer, not the account menu as well.',
        );
    }

    public function test_a_non_admin_is_not_offered_settings_at_all(): void
    {
        $html = $this->renderShell(User::factory()->create(['role' => UserRole::Viewer]));

        $this->assertStringNotContainsString('href="'.route('settings.general').'"', $html);
    }

    public function test_profile_and_logout_are_in_the_account_menu(): void
    {
        $html = $this->renderShell(User::factory()->create(['role' => UserRole::Admin]));

        $this->assertStringContainsString(route('settings.account'), $html);
        $this->assertStringContainsString(route('logout'), $html);
        // Logout stays a POST — a GET link would be a CSRF hole.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*method="POST"[^>]*action="'.preg_quote(route('logout'), '/').'"/',
            $html,
        );
    }
}
