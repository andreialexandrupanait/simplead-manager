<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\Components\ChannelForm;
use App\Models\NotificationChannel;
use App\Services\Notifications\WebhookNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-19: a custom notification webhook URL is fetched server-side by the
 * manager, so it must be SSRF-guarded both at save time and at send time. An
 * internal / metadata / private target must be rejected.
 */
class WebhookChannelSsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_webhook_channel_with_an_internal_url_is_rejected(): void
    {
        Livewire::test(ChannelForm::class)
            ->set('form.name', 'Evil hook')
            ->set('form.type', 'webhook')
            ->set('form.webhookUrl', 'http://169.254.169.254/latest/meta-data/')
            ->set('form.webhookMethod', 'POST')
            ->call('save')
            ->assertHasErrors('form.webhookUrl');

        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_send_time_guard_blocks_an_internal_webhook_url(): void
    {
        $channel = NotificationChannel::create([
            'name' => 'Legacy internal hook',
            'type' => 'webhook',
            'config' => ['url' => 'http://pgsql:5432/notify', 'method' => 'POST'],
        ]);

        $result = WebhookNotificationSender::send($channel, 'test.event', null, ['message' => 'hi']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SSRF', $result['error']);
    }
}
