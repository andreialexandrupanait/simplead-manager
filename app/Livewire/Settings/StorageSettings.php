<?php

namespace App\Livewire\Settings;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Livewire\Attributes\On;
use Livewire\Component;

class StorageSettings extends Component
{
    public function getDestinationsProperty()
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

            session()->flash('storage-success', "Connection test for {$destination->name} " . ($passed ? 'passed.' : 'failed.'));
        } catch (\Exception $e) {
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
    }

    public function deleteDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        if ($destination->backups()->exists()) {
            session()->flash('storage-error', "Cannot delete {$destination->name} — it has existing backups.");
            return;
        }

        $destination->delete();
        session()->flash('storage-success', "Storage destination deleted.");
    }

    #[On('storage-destination-saved')]
    public function refreshList(): void
    {
        // Livewire will re-render
    }

    public function render()
    {
        return view('livewire.settings.storage-settings');
    }
}
