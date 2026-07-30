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

            session()->flash('storage-success', "Connection test for {$destination->name} ".($passed ? 'passed.' : 'failed.'));
        } catch (RequestException|\RuntimeException $e) {
            $destination->update([
                'last_tested_at' => now(),
                'last_test_passed' => false,
                'last_test_error' => $e->getMessage(),
            ]);

            session()->flash('storage-error', "Connection test for {$destination->name} failed: {$e->getMessage()}");
        }
    }

    public function setDefault(int $id): void
    {
        StorageDestination::where('is_default', true)->update(['is_default' => false]);
        StorageDestination::findOrFail($id)->update(['is_default' => true]);

        unset($this->destinations);
    }

    public function deleteDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        if ($destination->backups()->exists()) {
            session()->flash('storage-error', "Cannot delete {$destination->name} — it has existing backups.");

            return;
        }

        $destination->delete();
        unset($this->destinations);

        session()->flash('storage-success', 'Storage destination deleted.');
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
