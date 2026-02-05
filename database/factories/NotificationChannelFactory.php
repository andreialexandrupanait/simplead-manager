<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['email', 'slack', 'discord', 'webhook']);
        $config = match ($type) {
            'email' => ['recipients' => [fake()->safeEmail()]],
            'slack' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/xxx'],
            'discord' => ['webhook_url' => 'https://discord.com/api/webhooks/123/abc'],
            'webhook' => ['url' => fake()->url(), 'method' => 'POST'],
        };

        return [
            'name' => ucfirst($type) . ' ' . fake()->word(),
            'type' => $type,
            'config' => $config,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
