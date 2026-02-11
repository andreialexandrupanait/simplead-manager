<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\DashboardWidget;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

abstract class BaseWidget extends Component
{
    public DashboardWidget $widget;
    public bool $isLoaded = false;
    public array $config = [];

    abstract public function getWidgetType(): string;
    abstract public function getDefaultConfig(): array;
    abstract public function getTitle(): string;
    abstract public function getWidgetData(): array;

    public function mount(DashboardWidget $widget)
    {
        $this->widget = $widget;
        $this->config = $widget->config ?? $this->getDefaultConfig();
    }

    #[Computed]
    public function cacheKey(): string
    {
        $configHash = md5(json_encode($this->config));
        return "dashboard:widget:{$this->widget->user_id}:{$this->getWidgetType()}:{$configHash}";
    }

    public function loadWidget()
    {
        $this->isLoaded = true;
    }

    public function updateConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);

        $this->widget->update([
            'config' => $this->config
        ]);

        // Clear cache when config changes
        Cache::forget($this->cacheKey);

        $this->dispatch('widget-updated', widgetId: $this->widget->id);
    }

    public function refreshWidget()
    {
        Cache::forget($this->cacheKey);
        $this->isLoaded = false;
        $this->loadWidget();
    }

    #[Computed(persist: true)]
    public function data(): array
    {
        if (!$this->isLoaded) {
            return [];
        }

        return Cache::remember($this->cacheKey, 300, function () {
            return $this->getWidgetData();
        });
    }

    public function getIcon(): string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getMinWidth(): int
    {
        return 3;
    }

    public function getMinHeight(): int
    {
        return 2;
    }

    public function render()
    {
        return view('livewire.dashboard.widgets.' . str_replace('_', '-', $this->getWidgetType()));
    }
}
