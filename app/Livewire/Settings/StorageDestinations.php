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
