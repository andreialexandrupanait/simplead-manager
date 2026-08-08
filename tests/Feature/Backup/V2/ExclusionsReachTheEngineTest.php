<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Models\BackupSession;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Components\BackupScheduleForm;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Backup\BackupLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Excluding a folder from backups has to reach the thing doing the backup.
 *
 * Three separate halves of this feature existed and none of them met.
 *
 * The plugin has had a complete exclusion engine the whole time — folders,
 * globs, extensions, size and age bounds, include-or-exclude with deterministic
 * precedence and a policy hash — reached through `scope['rules']`.
 *
 * `backup_configs` has had `exclude_paths` and `exclude_tables` columns, absent
 * from the model's fillable and casts, used by no row on the fleet.
 *
 * And the engine's own console had a per-run exclusions box, which wrote to
 * `backup_sessions.exclusions` — a column the runner does not read.
 *
 * So the answer to "can I keep a folder out of my backups" was no, three times
 * over, while the code to do it sat finished on the far side of the wire.
 */
class ExclusionsReachTheEngineTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();

        $this->site = Site::factory()->create(['is_connected' => true]);
        // Pinned, not left to the factory. Its defaults randomise `type` across
        // full|files|database, and the schedule form validates `in:full,database`
        // — so one run in three loaded a config the form refused to save, and the
        // test failed on a die roll rather than on the behaviour it is about.
        BackupConfig::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => StorageDestination::factory()->create([
                'type' => 's3', 'is_active' => true, 'is_default' => true,
            ])->id,
            'is_enabled' => true,
            'type' => 'full',
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => 'Europe/Bucharest',
            'retention_type' => 'count',
            'retention_value' => 30,
        ]);

        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', ['*']);
    }

    public function test_what_the_form_saves_is_what_the_backup_is_told(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupScheduleForm::class, ['site' => $this->site])
            ->call('openModal')
            ->set('exclude_paths', "wp-content/cache\n**/*.log\n")
            ->set('exclude_tables', "wp_actionscheduler_logs\n")
            ->call('save');

        $config = $this->site->backupConfig()->first();
        $this->assertSame(['wp-content/cache', '**/*.log'], $config->exclude_paths);
        $this->assertSame(['wp_actionscheduler_logs'], $config->exclude_tables);

        app(BackupLauncher::class)->launch($this->site->fresh(), 'full', 'manual');

        $session = BackupSession::where('site_id', $this->site->id)->firstOrFail();
        $this->assertSame(['wp-content/cache', '**/*.log'], $session->scope['rules']);
        $this->assertSame(['wp_actionscheduler_logs'], $session->scope['exclude_tables']);
    }

    /**
     * Blank lines and stray spaces are what people actually type. An empty
     * pattern is not a rule, and shipping one to thirty sites is worse than
     * dropping it here.
     */
    public function test_blank_lines_are_not_stored_as_rules(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupScheduleForm::class, ['site' => $this->site])
            ->call('openModal')
            ->set('exclude_paths', "wp-content/cache\n\n   \n  wp-content/uploads/tmp  \n")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['wp-content/cache', 'wp-content/uploads/tmp'],
            $this->site->backupConfig()->first()->exclude_paths,
        );
    }

    /**
     * The pickers open without falling over.
     *
     * Worth its own test because the render test does not press anything: a
     * property renamed during the rewrite left `openPicker()` reading
     * `$pickerFolders`, which no longer existed, and the whole suite stayed green
     * because nothing ever called it. The screen only failed in a browser.
     */
    public function test_the_pickers_open(): void
    {
        $component = Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupScheduleForm::class, ['site' => $this->site])
            ->call('openModal');

        $component->call('openPicker')->assertSet('pickerOpen', true);
        $component->call('openTablePicker')->assertSet('tablePickerOpen', true);
    }

    /**
     * Opening a folder must not carry the whole tree with it.
     *
     * The first version held the complete listing in a public Livewire property.
     * That is component state: three and a half megabytes serialised into every
     * subsequent request — expanding a folder, ticking a box — which is past
     * Livewire's one-megabyte payload limit, so the second click on anything
     * returned a 500. The screen worked exactly once.
     *
     * The component now holds only the levels that are open. This asserts the
     * shape rather than a byte count: what is kept is what was asked for.
     */
    public function test_opening_a_folder_keeps_only_that_level(): void
    {
        $component = Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupScheduleForm::class, ['site' => $this->site])
            ->call('openModal')
            ->call('openPicker');

        $component->call('toggleFolder', 'wp-content');
        $this->assertContains('wp-content', $component->get('pickerOpenPaths'));

        // The invariant: state holds the root and the folders actually opened,
        // and nothing else. Never the whole listing.
        $this->assertEmpty(
            array_diff(array_keys($component->get('pickerLevels')), ['', ...$component->get('pickerOpenPaths')]),
            'no level is kept that was not asked for',
        );

        $component->call('toggleFolder', 'wp-content');
        $this->assertNotContains('wp-content', $component->get('pickerOpenPaths'), 'and it closes again');
    }

    /** A site that excludes nothing sends no rules at all, rather than an empty list. */
    public function test_a_site_with_no_exclusions_sends_none(): void
    {
        app(BackupLauncher::class)->launch($this->site, 'full', 'manual');

        $session = BackupSession::where('site_id', $this->site->id)->firstOrFail();
        $this->assertArrayNotHasKey('rules', $session->scope);
        $this->assertArrayNotHasKey('exclude_tables', $session->scope);
    }
}
