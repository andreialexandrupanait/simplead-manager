<?php

namespace App\Livewire\Settings;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Services\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationSettings extends Component
{
    // Notification preferences
    public bool $notifyDown = true;

    public bool $notifyRecovery = true;

    public bool $notifySslExpiring = true;

    public bool $notifyDegraded = false;

    // Quiet hours
    public bool $quietHoursEnabled = false;

    public string $quietHoursStart = '22:00';

    public string $quietHoursEnd = '07:00';

    public function mount(SettingsService $settings): void
    {
        $this->notifyDown = (bool) $settings->get('notify_down', true);
        $this->notifyRecovery = (bool) $settings->get('notify_recovery', true);
        $this->notifySslExpiring = (bool) $settings->get('notify_ssl_expiring', true);
        $this->notifyDegraded = (bool) $settings->get('notify_degraded', false);
        $this->quietHoursEnabled = (bool) $settings->get('quiet_hours_enabled', false);
        $this->quietHoursStart = $settings->get('quiet_hours_start', '22:00');
        $this->quietHoursEnd = $settings->get('quiet_hours_end', '07:00');
    }

    public function savePreferences(SettingsService $settings): void
    {
        $settings->set('notify_down', $this->notifyDown, 'notifications', 'boolean');
        $settings->set('notify_recovery', $this->notifyRecovery, 'notifications', 'boolean');
        $settings->set('notify_ssl_expiring', $this->notifySslExpiring, 'notifications', 'boolean');
        $settings->set('notify_degraded', $this->notifyDegraded, 'notifications', 'boolean');
        $settings->set('quiet_hours_enabled', $this->quietHoursEnabled, 'notifications', 'boolean');
        $settings->set('quiet_hours_start', $this->quietHoursStart, 'notifications', 'string');
        $settings->set('quiet_hours_end', $this->quietHoursEnd, 'notifications', 'string');

        $this->dispatch('notify', type: 'success', message: 'Notification preferences saved.');
    }

    public function deleteChannel(int $id): void
    {
        NotificationChannel::findOrFail($id)->delete();
    }

    public function toggleChannel(int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);
        $channel->update(['is_active' => ! $channel->is_active]);
    }

    public function testChannel(int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);

        SendNotificationJob::dispatch(
            channel: $channel,
            site: null,
            event: 'test',
            title: 'Test Notification',
            message: 'This is a test notification from SimpleAD Manager.',
            fields: [
                ['title' => 'Channel', 'value' => $channel->name, 'short' => true],
                ['title' => 'Type', 'value' => ucfirst($channel->type), 'short' => true],
            ],
            severity: 'info',
        );

        $this->dispatch('notify', type: 'info', message: "Test notification dispatched to {$channel->name}.");
    }

    #[On('channels-updated')]
    public function refreshChannels(): void
    {
        // Livewire will re-render automatically
    }

    #[Computed]
    public function channels()
    {
        return NotificationChannel::orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.settings.notification-settings')
            ->layout('components.layouts.app', ['title' => 'Notification Settings']);
    }
}
