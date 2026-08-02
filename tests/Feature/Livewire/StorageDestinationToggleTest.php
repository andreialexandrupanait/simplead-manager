<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\StorageDestinations;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Retiring a storage provider without deleting anything.
 *
 * `is_active` decided where every backup went and could only be set once, when the destination was
 * created — nothing in the interface could turn one off. Retiring a provider therefore meant either
 * deleting it, which is refused as soon as it holds backups, or leaving it live and trusting that
 * nothing would choose it.
 */
class StorageDestinationToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        StorageDestination::query()->update(['is_active' => false, 'is_default' => false]);
    }

    public function test_a_destination_can_be_switched_off_and_back_on(): void
    {
        $keep = $this->destination('Hetzner', default: true);
        $retire = $this->destination('Dropbox');

        Livewire::actingAs($this->admin)
            ->test(StorageDestinations::class)
            ->call('toggleActive', $retire->id);

        $this->assertFalse((bool) $retire->fresh()->is_active);
        $this->assertTrue((bool) $keep->fresh()->is_active, 'the one still in use is untouched');

        Livewire::actingAs($this->admin)
            ->test(StorageDestinations::class)
            ->call('toggleActive', $retire->id);

        $this->assertTrue((bool) $retire->fresh()->is_active, 'and it can come back');
    }

    public function test_the_last_active_destination_cannot_be_switched_off(): void
    {
        // Every site without an explicit destination falls back to whichever is active, and so does
        // the platform's own nightly database dump. With none active both stop, silently — a
        // fleet-wide outage from one click.
        $only = $this->destination('Hetzner', default: true);

        Livewire::actingAs($this->admin)
            ->test(StorageDestinations::class)
            ->call('toggleActive', $only->id);

        $this->assertTrue((bool) $only->fresh()->is_active);
    }

    public function test_the_default_destination_cannot_be_switched_off_while_it_is_the_default(): void
    {
        $default = $this->destination('Hetzner', default: true);
        $this->destination('Spare');

        Livewire::actingAs($this->admin)
            ->test(StorageDestinations::class)
            ->call('toggleActive', $default->id);

        $this->assertTrue(
            (bool) $default->fresh()->is_active,
            'switching off the default would send every site to whatever happened to be next',
        );
    }

    public function test_switching_off_keeps_the_backups_that_are_already_there(): void
    {
        $keep = $this->destination('Hetzner', default: true);
        $retire = $this->destination('Dropbox');
        $backup = \App\Models\Backup::factory()->create(['storage_destination_id' => $retire->id]);

        Livewire::actingAs($this->admin)
            ->test(StorageDestinations::class)
            ->call('toggleActive', $retire->id);

        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'storage_destination_id' => $retire->id]);
        $this->assertNotNull($keep->fresh());
    }

    private function destination(string $name, bool $default = false): StorageDestination
    {
        return StorageDestination::factory()->create([
            'name' => $name,
            'type' => 'hetzner_objectstorage',
            'is_active' => true,
            'is_default' => $default,
        ]);
    }
}
