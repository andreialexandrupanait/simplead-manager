<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\NotificationEscalationRule;
use App\Models\NotificationTemplate;
use App\Services\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationSettings extends Component
{
    // Notification preferences. Each of these gates a real sender: notify_down
    // and notify_recovery in NotifyIncident, notify_ssl_expiring in CheckSsl.
    // A fourth toggle, "Degraded Performance", was offered here for an event
    // nothing ever emits — it is gone rather than left pretending.
    public bool $notifyDown = true;

    public bool $notifyRecovery = true;

    public bool $notifySslExpiring = true;

    // Quiet hours
    public bool $quietHoursEnabled = false;

    public string $quietHoursStart = '22:00';

    public string $quietHoursEnd = '07:00';

    public function mount(SettingsService $settings): void
    {
        $this->notifyDown = (bool) $settings->get('notify_down', true);
        $this->notifyRecovery = (bool) $settings->get('notify_recovery', true);
        $this->notifySslExpiring = (bool) $settings->get('notify_ssl_expiring', true);
        $this->quietHoursEnabled = (bool) $settings->get('quiet_hours_enabled', false);

        // SettingsService::get() returns the *stored* value when the row exists,
        // and a stored NULL comes back as null rather than as the default — which
        // is a TypeError against these string properties under strict_types.
        $this->quietHoursStart = $settings->get('quiet_hours_start', '22:00') ?? '22:00';
        $this->quietHoursEnd = $settings->get('quiet_hours_end', '07:00') ?? '07:00';
    }

    public function savePreferences(SettingsService $settings): void
    {
        $this->validate([
            'quietHoursStart' => 'required|date_format:H:i',
            'quietHoursEnd' => 'required|date_format:H:i',
        ]);

        $settings->set('notify_down', $this->notifyDown, 'notifications', 'boolean');
        $settings->set('notify_recovery', $this->notifyRecovery, 'notifications', 'boolean');
        $settings->set('notify_ssl_expiring', $this->notifySslExpiring, 'notifications', 'boolean');
        $settings->set('quiet_hours_enabled', $this->quietHoursEnabled, 'notifications', 'boolean');
        $settings->set('quiet_hours_start', $this->quietHoursStart, 'notifications', 'string');
        $settings->set('quiet_hours_end', $this->quietHoursEnd, 'notifications', 'string');

        $this->dispatch('notify', type: 'success', message: __('Notification preferences saved.'));
    }

    public function deleteChannel(int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);
        $name = $channel->name;
        $channel->delete();

        unset($this->channels);

        $this->dispatch('notify', type: 'success', message: __('Channel :name deleted.', ['name' => $name]));
    }

    public function toggleChannel(int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);
        $channel->update(['is_active' => ! $channel->is_active]);

        unset($this->channels);

        $this->dispatch('notify', type: 'success', message: $channel->is_active
            ? __('Channel :name enabled.', ['name' => $channel->name])
            : __('Channel :name disabled.', ['name' => $channel->name]));
    }

    public function testChannel(int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);

        SendNotificationJob::dispatch(
            channel: $channel,
            site: null,
            event: 'test',
            title: __('Test Notification'),
            message: __('This is a test notification from SimpleAD Manager.'),
            fields: [
                ['title' => 'Channel', 'value' => $channel->name, 'short' => true],
                ['title' => 'Type', 'value' => ucfirst($channel->type), 'short' => true],
            ],
            severity: 'info',
        );

        $this->dispatch('notify', type: 'info', message: __('Test notification dispatched to :name.', ['name' => $channel->name]));
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

    // --- Message Templates ---

    public ?int $editingTemplateId = null;

    public string $templateEvent = '';

    public string $templateTitle = '';

    public string $templateMessage = '';

    public bool $templateIsActive = true;

    #[Computed]
    public function notificationTemplates()
    {
        return NotificationTemplate::orderBy('event')->get();
    }

    public function editTemplate(?int $id = null): void
    {
        if ($id) {
            $template = NotificationTemplate::findOrFail($id);
            $this->editingTemplateId = $id;
            $this->templateEvent = $template->event;
            $this->templateTitle = $template->title_template;
            $this->templateMessage = $template->message_template;
            $this->templateIsActive = $template->is_active;
        } else {
            $this->reset('editingTemplateId', 'templateEvent', 'templateTitle', 'templateMessage');
            $this->templateIsActive = true;
        }
        $this->dispatch('open-modal-notification-template');
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'templateEvent' => 'required|string|max:100',
            'templateTitle' => 'required|string|max:255',
            'templateMessage' => 'required|string|max:2000',
        ]);

        NotificationTemplate::updateOrCreate(
            ['id' => $this->editingTemplateId],
            [
                'event' => $this->templateEvent,
                'title_template' => $this->templateTitle,
                'message_template' => $this->templateMessage,
                'is_active' => $this->templateIsActive,
            ]
        );

        $this->dispatch('close-modal-notification-template');
        $this->reset('editingTemplateId', 'templateEvent', 'templateTitle', 'templateMessage');
        unset($this->notificationTemplates);

        // A session flash never reaches the user here: this component's view is
        // re-rendered on its own, the page shell that renders flash alerts is
        // not — so the message stayed in the session and surfaced on whatever
        // page was loaded next. The toast stack listens for `notify` and works
        // over an open modal.
        $this->dispatch('notify', type: 'success', message: __('Template saved.'));
    }

    public function deleteTemplate(int $id): void
    {
        NotificationTemplate::findOrFail($id)->delete();
        unset($this->notificationTemplates);

        $this->dispatch('notify', type: 'success', message: __('Template deleted.'));
    }

    // --- Escalation Rules ---

    public ?int $escalationSourceId = null;

    public ?int $escalationTargetId = null;

    public int $escalationDelay = 15;

    #[Computed]
    public function escalationRules()
    {
        return NotificationEscalationRule::with(['sourceChannel', 'escalationChannel'])->get();
    }

    public function addEscalationRule(): void
    {
        $this->validate([
            'escalationSourceId' => 'required|exists:notification_channels,id',
            'escalationTargetId' => 'required|exists:notification_channels,id|different:escalationSourceId',
            'escalationDelay' => 'required|integer|min:5|max:120',
        ]);

        NotificationEscalationRule::create([
            'source_channel_id' => $this->escalationSourceId,
            'escalation_channel_id' => $this->escalationTargetId,
            'delay_minutes' => $this->escalationDelay,
            'severity' => 'critical',
        ]);

        $this->reset('escalationSourceId', 'escalationTargetId');
        $this->escalationDelay = 15;
        unset($this->escalationRules);

        $this->dispatch('notify', type: 'success', message: __('Escalation rule created.'));
    }

    public function deleteEscalationRule(int $id): void
    {
        NotificationEscalationRule::findOrFail($id)->delete();
        unset($this->escalationRules);

        $this->dispatch('notify', type: 'success', message: __('Escalation rule removed.'));
    }

    public function render()
    {
        return view('livewire.settings.notification-settings');
    }
}
