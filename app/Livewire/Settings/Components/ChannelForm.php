<?php

declare(strict_types=1);

namespace App\Livewire\Settings\Components;

use App\Exceptions\SsrfException;
use App\Livewire\Forms\ChannelFormData;
use App\Models\NotificationChannel;
use App\Services\Security\SsrfGuard;
use Livewire\Attributes\On;
use Livewire\Component;

class ChannelForm extends Component
{
    public ?int $channelId = null;

    public ChannelFormData $form;

    #[On('open-channel-form')]
    public function openModal(?int $channelId = null): void
    {
        $this->resetValidation();
        $this->channelId = $channelId;

        if ($channelId) {
            $channel = NotificationChannel::findOrFail($channelId);
            $this->form->setFromChannel($channel);
        } else {
            $this->form->resetFormData();
        }

        $this->dispatch('open-modal-channel-form');
    }

    public function save(): void
    {
        $this->form->validate();

        // SSRF guard: reject a custom webhook URL that resolves to the internal
        // network / loopback / metadata endpoint at save time.
        if ($this->form->type === 'webhook') {
            try {
                app(SsrfGuard::class)->assertPublicUrl($this->form->webhookUrl);
            } catch (SsrfException) {
                $this->addError('form.webhookUrl', 'This webhook URL is not allowed — it points to a private or internal address.');

                return;
            }
        }

        $config = match ($this->form->type) {
            'email' => ['address' => $this->form->emailAddress],
            'slack', 'discord' => ['webhook_url' => $this->form->webhookUrl],
            'telegram' => [
                'bot_token' => encrypt($this->form->telegramBotToken),
                'chat_id' => $this->form->telegramChatId,
            ],
            'webhook' => array_filter([
                'url' => $this->form->webhookUrl,
                'method' => $this->form->webhookMethod,
                'headers' => $this->form->webhookHeaders ? json_decode($this->form->webhookHeaders, true) : [],
                'signing_secret' => $this->form->webhookSigningSecret ?: null,
            ]),
            default => [],
        };

        $data = [
            'name' => $this->form->name,
            'type' => $this->form->type,
            'config' => $config,
            'is_default' => $this->form->is_default,
            'event_subscriptions' => ! empty($this->form->eventSubscriptions) ? $this->form->eventSubscriptions : null,
        ];

        if ($this->channelId) {
            NotificationChannel::findOrFail($this->channelId)->update($data);
        } else {
            NotificationChannel::create($data);
        }

        $this->dispatch('close-modal-channel-form');
        $this->dispatch('channels-updated');
    }

    public function render()
    {
        return view('livewire.settings.components.channel-form');
    }
}
