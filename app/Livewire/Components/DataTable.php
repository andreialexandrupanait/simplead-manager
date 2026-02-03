<?php

namespace App\Livewire\Components;

use Livewire\Component;

class DataTable extends Component
{
    public string $search = '';

    public string $sortField = '';

    public string $sortDirection = 'asc';

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->dispatch('data-table-sort', field: $this->sortField, direction: $this->sortDirection);
    }

    public function updatedSearch(): void
    {
        $this->dispatch('data-table-search', search: $this->search);
    }

    public function render()
    {
        return view('livewire.components.data-table');
    }
}
