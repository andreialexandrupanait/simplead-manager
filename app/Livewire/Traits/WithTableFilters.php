<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait WithTableFilters
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

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

        $escaped = '%'.$this->escapeLike($this->search).'%';

        return $query->where(function ($q) use ($columns, $escaped) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $escaped);
            }
        });
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
}
