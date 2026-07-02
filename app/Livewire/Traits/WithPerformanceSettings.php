<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

trait WithPerformanceSettings
{
    public bool $showSettings = false;

    public string $settingsFrequency = 'daily';

    public string $settingsTestTime = '04:00';

    public ?int $settingsDayOfWeek = null;

    public bool $settingsAlertOnDrop = true;

    public int $settingsThreshold = 10;

    public function openSettings(): void
    {
        $this->loadSettings();
        $this->dispatch('open-modal-performance-settings');
    }

    public function updateSettings(): void
    {
        $this->authorizeSiteModification($this->site);

        if (! $this->monitor) {
            return;
        }

        $this->validate([
            'settingsFrequency' => 'required|in:daily,weekly,monthly',
            'settingsTestTime' => 'required|date_format:H:i',
            'settingsThreshold' => 'required|integer|min:1|max:100',
        ]);

        $this->monitor->update([
            'frequency' => $this->settingsFrequency,
            'test_time' => $this->settingsTestTime,
            'day_of_week' => $this->settingsFrequency === 'weekly' ? $this->settingsDayOfWeek : null,
            'alert_on_score_drop' => $this->settingsAlertOnDrop,
            'score_drop_threshold' => $this->settingsThreshold,
        ]);

        unset($this->monitor);
        $this->dispatch('close-modal-performance-settings');
        session()->flash('message', 'Performance settings updated.');
    }

    private function loadSettings(): void
    {
        $monitor = $this->site->performanceMonitor;
        if ($monitor) {
            $this->settingsFrequency = $monitor->frequency;
            $this->settingsTestTime = $monitor->test_time;
            $this->settingsDayOfWeek = $monitor->day_of_week;
            $this->settingsAlertOnDrop = $monitor->alert_on_score_drop;
            $this->settingsThreshold = $monitor->score_drop_threshold;
        }
    }
}
