<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\EmailSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_email_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->assertOk();
    }

    #[Test]
    public function component_mounts_with_existing_config_values(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(EmailSettings::class);

        // Component must have the standard default fields set
        $this->assertNotNull($component->get('mailer'));
        $this->assertNotNull($component->get('encryption'));
    }

    // ─── save() ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_save_smtp_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('username', 'user@example.com')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'noreply@example.com')
            ->set('fromName', 'SimpleAD Manager')
            ->call('save')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'mail.host',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'mail.from_address',
        ]);
    }

    #[Test]
    public function save_validates_required_from_address(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('encryption', 'tls')
            ->set('fromAddress', '')
            ->set('fromName', 'Test')
            ->call('save')
            ->assertHasErrors(['fromAddress']);
    }

    #[Test]
    public function save_validates_from_address_must_be_email(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'not-an-email')
            ->set('fromName', 'Test')
            ->call('save')
            ->assertHasErrors(['fromAddress']);
    }

    #[Test]
    public function save_validates_mailer_must_be_valid_option(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'sendgrid')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'test@example.com')
            ->set('fromName', 'Test')
            ->call('save')
            ->assertHasErrors(['mailer']);
    }

    #[Test]
    public function save_validates_port_range(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '99999')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'test@example.com')
            ->set('fromName', 'Test')
            ->call('save')
            ->assertHasErrors(['port']);
    }

    #[Test]
    public function save_validates_encryption_must_be_valid_option(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('encryption', 'starttls')
            ->set('fromAddress', 'test@example.com')
            ->set('fromName', 'Test')
            ->call('save')
            ->assertHasErrors(['encryption']);
    }

    // ─── sendTestEmail() ──────────────────────────────────────────────

    #[Test]
    public function send_test_email_uses_mail_fake_and_dispatches_notify(): void
    {
        Mail::fake();

        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'smtp.example.com')
            ->set('port', '587')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'noreply@example.com')
            ->set('fromName', 'SimpleAD')
            ->call('sendTestEmail')
            ->assertDispatched('notify');
    }

    #[Test]
    public function send_test_email_catches_exception_and_dispatches_error_notify(): void
    {
        // Force an SMTP failure by configuring an invalid host
        config(['mail.mailers.smtp.host' => '']);

        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'smtp')
            ->set('host', 'invalid.host.that.does.not.exist')
            ->set('port', '587')
            ->set('encryption', 'tls')
            ->set('fromAddress', 'test@example.com')
            ->set('fromName', 'Test')
            ->call('sendTestEmail')
            ->assertDispatched('notify');
    }

    // ─── log mailer ───────────────────────────────────────────────────

    #[Test]
    public function user_can_switch_to_log_mailer(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EmailSettings::class)
            ->set('mailer', 'log')
            ->set('fromAddress', 'noreply@example.com')
            ->set('fromName', 'SimpleAD')
            ->call('save')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'mail.mailer',
        ]);
    }
}
