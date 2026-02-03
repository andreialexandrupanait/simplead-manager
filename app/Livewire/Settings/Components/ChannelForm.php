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

            match ($channel->type) {
                'email' => $this->emailAddress = $channel->config['address'] ?? '',
                'slack', 'discord' => $this->webhookUrl = $channel->config['webhook_url'] ?? '',
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
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:email,slack,discord,webhook',
        ]);

        $config = match ($this->type) {
            'email' => ['address' => $this->emailAddress],
            'slack', 'discord' => ['webhook_url' => $this->webhookUrl],
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
