<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Backup storage destinations — create, test, delete, pick a default.
 *
 * These were part of IntegrationsSettings, sharing a screen with Google,
 * Cloudflare and Unsplash API keys. A destination is not an integration: it is
 * where a backup lands, and the only thing that reads it is the Application
 * Backup screen. They live together now.
 */
class StorageDestinations extends Component
{
    #[Computed]
    public function destinations()
    {
        return StorageDestination::orderBy('name')->get();
    }

    public function testDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        try {
            $driver = StorageFactory::make($destination);
            $passed = $driver->test();

            $destination->update([
                'last_tested_at' => now(),
                'last_test_passed' => $passed,
                'last_test_error' => $passed ? null : 'Test returned false.',
            ]);

            // Was a `storage-*` session flash rendered by this component alone;
            // the page shows notify toasts centrally, so every other action on
            // the tab reported itself differently. All four speak toast now.
            $this->dispatch(
                'notify',
                type: $passed ? 'success' : 'error',
                message: $passed
                    ? __('Connection test for :name passed.', ['name' => $destination->name])
                    : __('Connection test for :name failed.', ['name' => $destination->name]),
            );
        } catch (RequestException|\RuntimeException $e) {
            $destination->update([
                'last_tested_at' => now(),
                'last_test_passed' => false,
                'last_test_error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', type: 'error', message: __('Connection test for :name failed: :error', [
                'name' => $destination->name,
                'error' => $e->getMessage(),
            ]));
        }
    }

    public function setDefault(int $id): void
    {
        StorageDestination::where('is_default', true)->update(['is_default' => false]);
        $destination = StorageDestination::findOrFail($id);
        $destination->update(['is_default' => true]);

        unset($this->destinations);

        $this->dispatch('notify', type: 'success', message: __(':name is now the default destination.', [
            'name' => $destination->name,
        ]));
    }

    /**
     * Take a destination out of use, or put it back.
     *
     * `is_active` decided everything and could only ever be set once, when the destination was
     * created: nothing in the interface could turn one off. So retiring a provider meant either
     * deleting it — impossible once it has backups — or leaving it live and hoping nothing chose
     * it. Both are worse than a switch.
     *
     * Refuses to switch off the last active one. Every site without an explicit destination falls
     * back to whichever is active ({@see StorageDestination::resolveForSite}), and so does the
     * platform's own nightly database dump; with none active, both stop silently. That is a
     * fleet-wide outage from one click, so it is not allowed to be one.
     */
    public function toggleActive(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        if ($destination->is_active && StorageDestination::where('is_active', true)->count() <= 1) {
            $this->dispatch('notify', type: 'error', message: __('Cannot switch off :name — it is the only active destination, and backups would have nowhere to go.', [
                'name' => $destination->name,
            ]));

            return;
        }

        if ($destination->is_active && $destination->is_default) {
            $this->dispatch('notify', type: 'error', message: __('Cannot switch off :name while it is the default. Make another destination the default first.', [
                'name' => $destination->name,
            ]));

            return;
        }

        $destination->update(['is_active' => ! $destination->is_active]);
        unset($this->destinations);

        $this->dispatch('notify', type: 'success', message: $destination->is_active
            ? __(':name is active again.', ['name' => $destination->name])
            : __(':name is switched off — nothing new will be stored there. Existing backups are untouched.', ['name' => $destination->name]));
    }

    public function deleteDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        if ($destination->backups()->exists()) {
            $this->dispatch('notify', type: 'error', message: __('Cannot delete :name — it has existing backups.', [
                'name' => $destination->name,
            ]));

            return;
        }

        $destination->delete();
        unset($this->destinations);

        $this->dispatch('notify', type: 'success', message: __('Storage destination deleted.'));
    }

    #[On('storage-destination-saved')]
    public function refreshList(): void
    {
        unset($this->destinations);
    }

    public function render()
    {
        return view('livewire.settings.storage-destinations');
    }
}
