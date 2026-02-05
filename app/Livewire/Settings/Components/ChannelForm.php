<?php

namespace App\Livewire\Settings\Components;

use App\Models\NotificationChannel;
use Livewire\Attributes\On;
use Livewire\Component;

class ChannelForm extends Component
{
    public ?int $channelId = null;
    public string $name = '';
    public string $type = 'email';
    public bool $is_default = false;

    // Type-specific fields
    public string $emailAddress = '';
    public string $webhookUrl = '';
    public string $webhookMethod = 'POST';
    public string $webhookHeaders = '';

    // Telegram fields
    public string $telegramBotToken = '';
    public string $telegramChatId = '';

    // Event subscriptions
    public array $eventSubscriptions = [];

    #[On('open-channel-form')]
    public function openModal(?int $channelId = null): void
    {
        $this->resetValidation();
        $this->channelId = $channelId;

        if ($channelId) {
            $channel = NotificationChannel::findOrFail($channelId);
            $this->name = $channel->name;
            $this->type = $channel->type;
            $this->is_default = $channel->is_default;
            $this->eventSubscriptions = $channel->event_subscriptions ?? [];

            match ($channel->type) {
                'email' => $this->emailAddress = $channel->config['address'] ?? '',
                'slack', 'discord' => $this->webhookUrl = $channel->config['webhook_url'] ?? '',
                'telegram' => (function () use ($channel) {
                    $this->telegramChatId = $channel->config['chat_id'] ?? '';
                    try {
                        $this->telegramBotToken = decrypt($channel->config['bot_token'] ?? '');
                    } catch (\Exception $e) {
                        $this->telegramBotToken = '';
                    }
                })(),
                'webhook' => (function () use ($channel) {
                    $this->webhookUrl = $channel->config['url'] ?? '';
                    $this->webhookMethod = $channel->config['method'] ?? 'POST';
                    $this->webhookHeaders = isset($channel->config['headers']) ? json_encode($channel->config['headers'], JSON_PRETTY_PRINT) : '';
                })(),
                default => null,
            };
        } else {
            $this->resetForm();
        }

        $this->dispatch('open-modal-channel-form');
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->type = 'email';
        $this->is_default = false;
        $this->emailAddress = '';
        $this->webhookUrl = '';
        $this->webhookMethod = 'POST';
        $this->webhookHeaders = '';
        $this->telegramBotToken = '';
        $this->telegramChatId = '';
        $this->eventSubscriptions = [];
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:email,slack,discord,webhook,telegram',
        ]);

        $config = match ($this->type) {
            'email' => ['address' => $this->emailAddress],
            'slack', 'discord' => ['webhook_url' => $this->webhookUrl],
            'telegram' => [
                'bot_token' => encrypt($this->telegramBotToken),
                'chat_id' => $this->telegramChatId,
            ],
            'webhook' => [
                'url' => $this->webhookUrl,
                'method' => $this->webhookMethod,
                'headers' => $this->webhookHeaders ? json_decode($this->webhookHeaders, true) : [],
            ],
            default => [],
        };

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'config' => $config,
            'is_default' => $this->is_default,
            'event_subscriptions' => !empty($this->eventSubscriptions) ? $this->eventSubscriptions : null,
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
