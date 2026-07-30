<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * When a settings action succeeds, the user must be told.
 *
 * `success` was flashed 21 times across the settings components and rendered in
 * exactly one blade, so saving a template, enabling two-factor or revoking a
 * token produced no visible response at all — and the message then leaked onto
 * whichever page rendered that key next, which was Integrations.
 *
 * The rule now: action results go to the toast stack via dispatch('notify'),
 * which is global and survives an open modal. Page-level flashes are rendered
 * once, by the six settings page wrappers.
 */
class SettingsFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const WRAPPER_DIR = 'resources/views/settings';

    /** The settings components, plus the two traits the Reports tab lives in. */
    private function settingsSources(): array
    {
        return array_merge(
            File::allFiles(base_path('app/Livewire/Settings')),
            [
                new \SplFileInfo(base_path('app/Livewire/Traits/WithTemplateForm.php')),
                new \SplFileInfo(base_path('app/Livewire/Traits/WithTemplateSiteAssignment.php')),
            ],
        );
    }

    public function test_no_settings_component_flashes_a_message_it_cannot_show(): void
    {
        $offenders = [];

        foreach ($this->settingsSources() as $file) {
            $source = file_get_contents($file->getPathname());

            if (preg_match_all("/session\(\)->flash\('([a-z-]+)'/", $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $key) {
                $offenders[] = $file->getFilename().' → '.$key;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Settings components use dispatch('notify') so the message is always visible.\n".
            'Still flashing: '.implode(', ', $offenders),
        );
    }

    public function test_every_settings_page_renders_the_shared_flash_pair(): void
    {
        foreach (File::files(base_path(self::WRAPPER_DIR)) as $file) {
            $source = $file->getContents();

            $this->assertStringContainsString(
                'key="success"',
                $source,
                "{$file->getFilename()} must render the success flash.",
            );
            $this->assertStringContainsString(
                'key="error"',
                $source,
                "{$file->getFilename()} must render the error flash.",
            );
        }
    }

    public function test_every_settings_page_has_exactly_one_page_title(): void
    {
        // Settings was the only feature area of forty-odd pages with no
        // x-ui.page-header, so it opened on a sticky grey tab strip where every
        // other page opens on an H1.
        foreach (File::files(base_path(self::WRAPPER_DIR)) as $file) {
            $this->assertSame(
                1,
                substr_count($file->getContents(), '<x-ui.page-header'),
                "{$file->getFilename()} must render exactly one page header.",
            );
        }
    }

    public function test_a_non_admin_still_reaches_the_account_page(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]))
            ->get('/settings/account')
            ->assertOk()
            ->assertSee(__('Account'));
    }
}
