<?php

namespace App\Livewire\Traits;

trait WithDeleteConfirmation
{
    public ?int $deletingId = null;
    public ?string $deletingName = null;

    public function confirmDelete(int $id, ?string $name = null): void
    {
        $this->deletingId = $id;
        $this->deletingName = $name;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deletingName = null;
    }

    /**
     * Execute the delete operation.
     * Implement this method in the component using this trait.
     */
    abstract protected function performDelete(): void;

    public function delete(): void
    {
        if (!$this->deletingId) {
            return;
        }

        $this->performDelete();

        $this->deletingId = null;
        $this->deletingName = null;
    }
}
