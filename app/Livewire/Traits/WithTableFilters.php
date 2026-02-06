<?php

namespace App\Livewire\Traits;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait WithTableFilters
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $filter = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Apply search to a query builder.
     * Override this method to customize search columns.
     */
    protected function applySearch($query, array $columns = ['name'])
    {
        if (empty($this->search)) {
            return $query;
        }

        return $query->where(function ($q) use ($columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$this->search}%");
            }
        });
    }
}
