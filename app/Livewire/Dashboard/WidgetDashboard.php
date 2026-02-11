<?php

namespace App\Livewire\Dashboard;

use App\Models\DashboardWidget;
use App\Services\WidgetService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class WidgetDashboard extends Component
{
    public bool $showAddWidgetModal = false;
    public bool $showResetModal = false;
    public bool $isEditMode = false;

    protected WidgetService $widgetService;

    public function boot(WidgetService $widgetService)
    {
        $this->widgetService = $widgetService;
    }

    public function mount()
    {
        // Auto-create default widgets on first visit
        if (!$this->hasWidgets()) {
            $this->widgetService->createDefaultWidgets(auth()->id());
        }
    }

    #[Computed]
    public function widgets(): Collection
    {
        return $this->widgetService->getVisibleWidgets(auth()->id());
    }

    #[Computed]
    public function availableWidgetTypes(): array
    {
        return $this->widgetService->getAvailableWidgetTypes(auth()->id());
    }

    public function hasWidgets(): bool
    {
        return DashboardWidget::where('user_id', auth()->id())->exists();
    }

    public function saveLayout(array $layout)
    {
        $this->widgetService->updateLayout(auth()->id(), $layout);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Layout saved successfully'
        ]);
    }

    public function addWidget(string $type)
    {
        try {
            $widget = $this->widgetService->addWidget(auth()->id(), $type);

            unset($this->widgets);
            unset($this->availableWidgetTypes);

            $this->showAddWidgetModal = false;

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Widget added successfully'
            ]);

            $this->dispatch('widget-added', widgetId: $widget->id);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function removeWidget(int $widgetId)
    {
        $this->widgetService->removeWidget(auth()->id(), $widgetId);

        unset($this->widgets);
        unset($this->availableWidgetTypes);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Widget removed successfully'
        ]);
    }

    public function toggleWidgetVisibility(int $widgetId)
    {
        $this->widgetService->toggleVisibility(auth()->id(), $widgetId);

        unset($this->widgets);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Widget visibility updated'
        ]);
    }

    public function resetToDefaults()
    {
        $this->widgetService->resetToDefaults(auth()->id());

        unset($this->widgets);
        unset($this->availableWidgetTypes);

        $this->showResetModal = false;
        $this->isEditMode = false;

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Dashboard reset to defaults'
        ]);

        // Reload the page to refresh all widgets
        $this->js('window.location.reload()');
    }

    public function toggleEditMode()
    {
        $this->isEditMode = !$this->isEditMode;
    }

    public function openAddWidgetModal()
    {
        $this->showAddWidgetModal = true;
    }

    public function closeAddWidgetModal()
    {
        $this->showAddWidgetModal = false;
    }

    public function openResetModal()
    {
        $this->showResetModal = true;
    }

    public function closeResetModal()
    {
        $this->showResetModal = false;
    }

    #[On('refresh-widgets')]
    public function refreshWidgets()
    {
        unset($this->widgets);
    }

    public function render()
    {
        return view('livewire.dashboard.widget-dashboard');
    }
}
