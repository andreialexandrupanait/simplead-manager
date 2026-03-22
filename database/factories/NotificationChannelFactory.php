<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['email', 'slack', 'discord', 'telegram']);

        return [
            'name' => ucfirst($type).' Notifications',
            'type' => $type,
            'config' => $this->configForType($type),
            'is_default' => false,
            'is_active' => true,
            'last_used_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'event_subscriptions' => null,
            'last_error' => null,
            'last_error_at' => null,
        ];
    }

    /**
     * Generate config for the given notification channel type.
     */
    private function configForType(string $type): array
    {
        return match ($type) {
            'email' => [
                'recipients' => [fake()->safeEmail(), fake()->safeEmail()],
            ],
            'slack' => [
                'webhook_url' => 'https://hooks.slack.com/services/'.fake()->regexify('[A-Z0-9]{9}/[A-Z0-9]{11}/[a-zA-Z0-9]{24}'),
                'channel' => '#'.fake()->randomElement(['monitoring', 'alerts', 'websites', 'devops']),
            ],
            'discord' => [
                'webhook_url' => 'https://discord.com/api/webhooks/'.fake()->numerify('##################').'/'.fake()->regexify('[a-zA-Z0-9_-]{68}'),
            ],
            'telegram' => [
                'bot_token' => fake()->numerify('##########').':'.fake()->regexify('[a-zA-Z0-9_-]{35}'),
                'chat_id' => fake()->numerify('-100##########'),
            ],
            default => [],
        };
    }

    /**
     * Indicate this is the default channel.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Indicate this channel is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate this is an email channel.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Email Notifications',
            'type' => 'email',
            'config' => $this->configForType('email'),
        ]);
    }

    /**
     * Indicate this is a Slack channel.
     */
    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Slack Notifications',
            'type' => 'slack',
            'config' => $this->configForType('slack'),
        ]);
    }

    /**
     * Indicate this is a Discord channel.
     */
    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Discord Notifications',
            'type' => 'discord',
            'config' => $this->configForType('discord'),
        ]);
    }

    /**
     * Indicate this is a Telegram channel.
     */
    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Telegram Notifications',
            'type' => 'telegram',
            'config' => $this->configForType('telegram'),
        ]);
    }

    /**
     * Indicate this channel has a recent error.
     */
    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_error' => fake()->randomElement([
                'Webhook returned HTTP 403',
                'Connection timed out',
                'Invalid API token',
                'Rate limit exceeded',
            ]),
            'last_error_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);
    }

    /**
     * Indicate this channel only subscribes to specific events.
     */
    public function subscribedTo(array $events): static
    {
        return $this->state(fn (array $attributes) => [
            'event_subscriptions' => $events,
        ]);
    }
}
