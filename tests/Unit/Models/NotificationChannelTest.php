<?php

namespace Tests\Unit\Models;

use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribed_to_returns_true_when_no_events_filter(): void
    {
        $channel = NotificationChannel::factory()->create([
            'event_subscriptions' => null,
        ]);

        $this->assertTrue($channel->subscribedTo('site_down'));
        $this->assertTrue($channel->subscribedTo('ssl_expiring'));
        $this->assertTrue($channel->subscribedTo('any_event'));
    }

    public function test_subscribed_to_returns_true_for_matching_event(): void
    {
        $channel = NotificationChannel::factory()->create([
            'event_subscriptions' => ['site_down', 'ssl_expiring', 'backup_failed'],
        ]);

        $this->assertTrue($channel->subscribedTo('site_down'));
        $this->assertTrue($channel->subscribedTo('ssl_expiring'));
        $this->assertTrue($channel->subscribedTo('backup_failed'));
    }

    public function test_subscribed_to_returns_false_for_non_matching_event(): void
    {
        $channel = NotificationChannel::factory()->create([
            'event_subscriptions' => ['site_down', 'ssl_expiring'],
        ]);

        $this->assertFalse($channel->subscribedTo('backup_failed'));
        $this->assertFalse($channel->subscribedTo('performance_drop'));
        $this->assertFalse($channel->subscribedTo('domain_expiring'));
    }
}
