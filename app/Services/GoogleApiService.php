<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleConnection;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Http;

class GoogleApiService
{
    protected GoogleConnection $connection;

    protected string $accessToken;

    public function __construct(GoogleConnection $connection)
    {
        $this->connection = $connection;
        $this->ensureValidToken();
    }

    protected function ensureValidToken(): void
    {
        try {
            if ($this->connection->token_expires_at->isFuture()) {
                $this->accessToken = decrypt($this->connection->access_token);

                return;
            }

            $refreshToken = decrypt($this->connection->refresh_token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Corrupt stored token — a permanent error; reconnection is required.
            $this->deactivate('Stored Google token could not be decrypted; reconnection required.');
            throw new \Exception('Google token encryption is invalid. Please reconnect your Google account in Settings > Integrations.');
        }

        $response = Http::timeout(10)->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            $status = $response->status();
            $error = (string) ($response->json('error') ?? '');

            // Transient failures (rate-limit / Google-side outage) must NOT
            // deactivate the connection — rethrow so the calling job retries.
            if ($status === 429 || $status >= 500) {
                throw new \RuntimeException(
                    "Google token refresh temporarily failed (HTTP {$status}); will retry."
                );
            }

            // Permanent auth failure (invalid_grant / other 4xx) — the refresh
            // token is dead, so deactivate and notify a human to reconnect.
            $reason = 'Google token refresh rejected (HTTP '.$status
                .($error !== '' ? ", {$error}" : '').').';
            $this->deactivate($reason);

            throw new \Exception('Failed to refresh Google token: '.($error !== '' ? $error : "HTTP {$status}"));
        }

        $tokens = $response->json();

        $this->connection->update([
            'access_token' => encrypt($tokens['access_token']),
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        $this->accessToken = $tokens['access_token'];
    }

    /**
     * Deactivate the connection on a permanent auth failure and notify, once.
     */
    protected function deactivate(string $reason): void
    {
        // Already deactivated — avoid duplicate notifications on repeated jobs.
        if (! $this->connection->is_active) {
            return;
        }

        $this->connection->update(['is_active' => false]);

        NotificationService::notifyAppEvent(
            event: 'google_connection_deactivated',
            title: 'Google Connection Deactivated',
            message: "The Google connection for {$this->connection->email} was deactivated — "
                .'Analytics and Search Console sync have stopped. Reconnect it in Settings > Integrations.',
            fields: [
                ['name' => 'Account', 'value' => (string) $this->connection->email],
                ['name' => 'Reason', 'value' => $reason],
            ],
            severity: 'warning',
        );
    }

    protected function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->withToken($this->accessToken)
            // Second param is the PendingRequest — typing it as Request caused a
            // TypeError in prod whenever a retry decision was evaluated.
            ->retry(3, 2000, function (\Throwable $exception, \Illuminate\Http\Client\PendingRequest $request): bool {
                if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
                    return false;
                }

                $status = $exception->response?->status();

                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false);
    }
}
