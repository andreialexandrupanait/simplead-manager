<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Livewire\Attributes\Computed;

trait WithPerformanceBudgets
{
    public array $budgetForm = [];

    #[Computed]
    public function budgetViolations(): array
    {
        $budgets = $this->monitor->budgets;
        if (empty($budgets)) {
            return [];
        }

        $test = $this->activeTest;
        if (! $test) {
            return [];
        }

        $labels = [
            'performance_score' => 'Performance Score',
            'lcp' => 'Largest Contentful Paint',
            'cls' => 'Cumulative Layout Shift',
            'tbt' => 'Total Blocking Time',
            'fcp' => 'First Contentful Paint',
            'si' => 'Speed Index',
            'total_size_bytes' => 'Total Page Size',
            'js_size' => 'JavaScript Size',
            'image_size' => 'Image Size',
        ];

        $minBudgets = ['performance_score'];
        $violations = [];

        foreach ($budgets as $key => $budget) {
            if ($budget === null || $budget === '') {
                continue;
            }

            $actual = $test->$key;
            if ($actual === null) {
                continue;
            }

            $budgetValue = (float) $budget;
            $isMin = in_array($key, $minBudgets);
            $exceeded = $isMin ? $actual < $budgetValue : $actual > $budgetValue;

            $violations[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'actual' => $actual,
                'budget' => $budgetValue,
                'exceeded' => $exceeded,
            ];
        }

        return $violations;
    }

    public function openBudgetModal(): void
    {
        $budgets = $this->monitor->budgets ?? [];
        $this->budgetForm = [
            'performance_score' => $budgets['performance_score'] ?? '',
            'lcp' => $budgets['lcp'] ?? '',
            'cls' => $budgets['cls'] ?? '',
            'tbt' => $budgets['tbt'] ?? '',
            'fcp' => $budgets['fcp'] ?? '',
            'si' => $budgets['si'] ?? '',
            'total_size_bytes' => $budgets['total_size_bytes'] ?? '',
            'js_size' => $budgets['js_size'] ?? '',
            'image_size' => $budgets['image_size'] ?? '',
        ];
        $this->dispatch('open-modal-edit-budgets');
    }

    public function saveBudgets(): void
    {
        if (! $this->monitor) {
            return;
        }

        $budgets = [];
        foreach ($this->budgetForm as $key => $value) {
            if ($value !== '' && $value !== null) {
                $budgets[$key] = is_numeric($value) ? (float) $value : $value;
            }
        }

        $this->monitor->update(['budgets' => ! empty($budgets) ? $budgets : null]);
        unset($this->monitor);
        unset($this->budgetViolations);
        $this->dispatch('close-modal-edit-budgets');
        session()->flash('message', 'Performance budgets updated.');
    }
}
