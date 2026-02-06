<?php

namespace App\Livewire\Traits;

trait WithModalForm
{
    public bool $showModal = false;
    public ?int $editingId = null;

    public function openModal(?int $id = null): void
    {
        $this->editingId = $id;

        if ($id) {
            $this->loadFormData($id);
        } else {
            $this->resetFormData();
        }

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingId = null;
        $this->resetValidation();
        $this->resetFormData();
    }

    public function openCreateModal(): void
    {
        $this->openModal(null);
    }

    public function openEditModal(int $id): void
    {
        $this->openModal($id);
    }

    /**
     * Reset form fields to default values.
     * Implement this method in the component.
     */
    abstract protected function resetFormData(): void;

    /**
     * Load existing data into form fields for editing.
     * Implement this method in the component.
     */
    abstract protected function loadFormData(int $id): void;
}
