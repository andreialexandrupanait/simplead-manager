<?php

namespace App\Livewire\Settings;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class EmailSettings extends Component
{
    public string $mailer = 'smtp';
    public string $host = '';
    public string $port = '587';
    public string $username = '';
    public string $password = '';
    public string $encryption = 'tls';
    public string $fromAddress = '';
    public string $fromName = '';
    public bool $hasPassword = false;

    public function mount(SettingsService $settings): void
    {
        $this->mailer = $settings->get('mail.mailer') ?? config('mail.default', 'smtp');
        $this->host = $settings->get('mail.host') ?? config('mail.mailers.smtp.host', '');
        $this->port = (string) ($settings->get('mail.port') ?? config('mail.mailers.smtp.port', '587'));
        $this->username = $settings->get('mail.username') ?? config('mail.mailers.smtp.username', '') ?? '';
        $this->encryption = $settings->get('mail.encryption') ?? $this->resolveEncryptionFromConfig();
        $this->fromAddress = $settings->get('mail.from_address') ?? config('mail.from.address', '');
        $this->fromName = $settings->get('mail.from_name') ?? config('mail.from.name', '');

        // Don't load password into the form — just track whether one exists
        $encryptedPassword = $settings->get('mail.password');
        $envPassword = config('mail.mailers.smtp.password');
        $this->hasPassword = !empty($encryptedPassword) || !empty($envPassword);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'mailer' => 'required|in:smtp,log',
            'host' => 'required_if:mailer,smtp|string|max:255',
            'port' => 'required_if:mailer,smtp|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'encryption' => 'required|in:tls,ssl,none',
            'fromAddress' => 'required|email|max:255',
            'fromName' => 'required|string|max:255',
        ]);

        $settings->set('mail.mailer', $this->mailer, 'mail');
        $settings->set('mail.host', $this->host, 'mail');
        $settings->set('mail.port', (int) $this->port, 'mail', 'integer');
        $settings->set('mail.username', $this->username, 'mail');
        if ($this->password !== '') {
            $settings->set('mail.password', encrypt($this->password), 'mail');
            $this->hasPassword = true;
        }
        $settings->set('mail.encryption', $this->encryption, 'mail');
        $settings->set('mail.from_address', $this->fromAddress, 'mail');
        $settings->set('mail.from_name', $this->fromName, 'mail');

        $this->applyRuntimeConfig();

        $this->dispatch('notify', type: 'success', message: 'Email settings saved.');
    }

    public function sendTestEmail(): void
    {
        // Ensure runtime config has the correct password from DB
        $this->applyRuntimeConfig();

        $recipient = auth()->user()->email;

        try {
            Mail::raw('This is a test email from SimpleAD Manager. If you received this, your SMTP settings are working correctly.', function ($message) use ($recipient) {
                $message->to($recipient)
                    ->subject('SimpleAD Manager — Test Email');
            });

            $this->dispatch('notify', type: 'success', message: "Test email sent to {$recipient}.");
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to send test email: ' . $e->getMessage());
        }
    }

    private function applyRuntimeConfig(): void
    {
        $scheme = match ($this->encryption) {
            'ssl' => 'smtps',
            default => null,
        };

        // Use the new password if entered, otherwise load existing from DB
        $password = $this->password;
        if ($password === '') {
            $settings = app(SettingsService::class);
            $encrypted = $settings->get('mail.password');
            if ($encrypted) {
                try { $password = decrypt($encrypted); } catch (\Exception $e) {}
            }
            if ($password === '') {
                $password = config('mail.mailers.smtp.password');
            }
        }

        config([
            'mail.default' => $this->mailer,
            'mail.mailers.smtp.host' => $this->host,
            'mail.mailers.smtp.port' => (int) $this->port,
            'mail.mailers.smtp.username' => $this->username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.from.address' => $this->fromAddress,
            'mail.from.name' => $this->fromName,
        ]);

        Mail::purge('smtp');
    }

    private function resolveEncryptionFromConfig(): string
    {
        $scheme = config('mail.mailers.smtp.scheme');

        if ($scheme === 'smtps') {
            return 'ssl';
        }

        return 'tls';
    }

    public function render()
    {
        return view('livewire.settings.email-settings')
            ->layout('components.layouts.app', ['title' => 'Email Settings']);
    }
}
