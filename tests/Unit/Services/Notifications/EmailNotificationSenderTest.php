<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\EmailNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function createChannel(array $config = []): NotificationChannel
    {
        return NotificationChannel::factory()->email()->create([
            'config' => array_merge([
                'address' => 'admin@example.com',
            ], $config),
        ]);
    }

    #[Test]
    public function sends_email_to_configured_address(): void
    {
        Mail::fake();

        $result = EmailNotificationSender::send(
            $this->createChannel(),
            TestMailable::class,
            ['Hello']
        );

        $this->assertTrue($result['success']);
        Mail::assertQueued(TestMailable::class);
    }

    #[Test]
    public function returns_error_without_address(): void
    {
        $result = EmailNotificationSender::send(
            $this->createChannel(['address' => null]),
            TestMailable::class,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No email address', $result['error']);
    }
}

class TestMailable extends Mailable
{
    public function __construct(public string $body = 'test') {}

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(htmlString: '<p>Test notification</p>');
    }
}
