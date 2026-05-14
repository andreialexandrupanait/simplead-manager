<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class ChannelFormData extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|in:email,slack,discord,webhook,telegram')]
    public string $type = 'email';

    public bool $is_default = false;

    // Type-specific fields
    public string $emailAddress = '';

    public string $webhookUrl = '';

    public string $webhookMethod = 'POST';

    public string $webhookHeaders = '';

    public string $webhookSigningSecret = '';

    // Telegram fields
    public string $telegramBotToken = '';

    public string $telegramChatId = '';

    // Event subscriptions
    public array $eventSubscriptions = [];

    /**
     * Dynamic validation rules based on selected channel type.
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|in:email,slack,discord,webhook,telegram',
        ];

        return match ($this->type) {
            'email' => array_merge($rules, [
                'emailAddress' => 'required|email|max:255',
            ]),
            'slack', 'discord' => array_merge($rules, [
                'webhookUrl' => 'required|url|max:2048',
            ]),
            'telegram' => array_merge($rules, [
                'telegramBotToken' => 'required|string|max:255',
                'telegramChatId' => 'required|string|max:255',
            ]),
            'webhook' => array_merge($rules, [
                'webhookUrl' => 'required|url|max:2048',
                'webhookMethod' => 'required|in:GET,POST,PUT,PATCH',
            ]),
            default => $rules,
        };
    }

    public function setFromChannel($channel): void
    {
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
                $this->webhookHeaders = isset($channel->config['headers']) ? (json_encode($channel->config['headers'], JSON_PRETTY_PRINT) ?: '') : '';
                $this->webhookSigningSecret = $channel->config['signing_secret'] ?? '';
            })(),
            default => null,
        };
    }

    public function resetFormData(): void
    {
        $this->name = '';
        $this->type = 'email';
        $this->is_default = false;
        $this->emailAddress = '';
        $this->webhookUrl = '';
        $this->webhookMethod = 'POST';
        $this->webhookHeaders = '';
        $this->webhookSigningSecret = '';
        $this->telegramBotToken = '';
        $this->telegramChatId = '';
        $this->eventSubscriptions = [];
    }
}
