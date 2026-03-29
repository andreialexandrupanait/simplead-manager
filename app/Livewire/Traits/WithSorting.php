<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Livewire\Attributes\Url;

trait WithSorting
{
    #[Url]
    public string $sortBy = 'name';

    #[Url]
    public string $sortDir = 'asc';

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function initializeWithSorting(): void
    {
        // Allow components to set default sort via protected properties
        if (! request()->has('sortBy') && property_exists($this, 'defaultSortBy')) {
            $this->sortBy = $this->defaultSortBy;
        }
        if (! request()->has('sortDir') && property_exists($this, 'defaultSortDir')) {
            $this->sortDir = $this->defaultSortDir;
        }
    }

    /**
     * Apply sorting to a query builder.
     */
    protected function applySorting($query)
    {
        return $query->orderBy($this->sortBy, $this->sortDir);
    }
}
