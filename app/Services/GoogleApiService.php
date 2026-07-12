<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleConnection;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleApiService
{
    /**
     * Treat the access token as expired this many seconds BEFORE its real
     * expiry, so an in-flight API call started just under the wire does not get
     * a 401 mid-request (P2-49).
     */
    private const EXPIRY_SKEW_SECONDS = 60;

    /**
     * Seconds a concurrent job waits for the refresh lock before giving up and
     * reusing whatever the winner stored (P2-49).
     */
    private const LOCK_WAIT_SECONDS = 10;

    protected GoogleConnection $connection;

    protected string $accessToken;

    public function __construct(GoogleConnection $connection)
    {
        $this->connection = $connection;
        $this->ensureValidToken();
    }

    protected function ensureValidToken(): void
    {
        if ($this->tokenIsFresh()) {
            $this->useStoredAccessToken();

            return;
        }

        // Serialise concurrent refreshes (P2-49): only one job hits Google's
        // token endpoint at a time; the rest wait for it and reuse the fresh
        // token, instead of stampeding and invalidating each other's new
        // access token.
        $lock = Cache::lock('google-token-refresh:'.$this->connection->id, 30);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException $e) {
            // Another refresh is taking a long time — trust whatever it stored.
            $this->connection->refresh();
            $this->useStoredAccessToken();

            return;
        }

        try {
            // A concurrent job may have refreshed while we waited for the lock;
            // reload from the DB and re-check before spending a refresh call.
            $this->connection->refresh();

            $expiresAt = $this->connection->token_expires_at;
            if ($expiresAt !== null && $expiresAt->isAfter(now()->addSeconds(self::EXPIRY_SKEW_SECONDS))) {
                $this->useStoredAccessToken();

                return;
            }

            $this->refreshAccessToken();
        } finally {
            $lock->release();
        }
    }

    /**
     * A token is only "fresh" if it will still be valid after the skew window,
     * so calls started near the boundary don't 401 in flight.
     */
    private function tokenIsFresh(): bool
    {
        $expiresAt = $this->connection->token_expires_at;

        return $expiresAt !== null
            && $expiresAt->isAfter(now()->addSeconds(self::EXPIRY_SKEW_SECONDS));
    }

    private function useStoredAccessToken(): void
    {
        try {
            $this->accessToken = decrypt($this->connection->access_token);
        } catch (DecryptException $e) {
            // Corrupt stored token — a permanent error; reconnection is required.
            $this->deactivate('Stored Google token could not be decrypted; reconnection required.');
            throw new \Exception('Google token encryption is invalid. Please reconnect your Google account in Settings > Integrations.');
        }
    }

    private function refreshAccessToken(): void
    {
        try {
            $refreshToken = decrypt($this->connection->refresh_token);
        } catch (DecryptException $e) {
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

        $updates = [
            'access_token' => encrypt($tokens['access_token']),
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ];

        // Google omits refresh_token on refresh responses; only persist a
        // rotated one when it is actually present and non-empty (P2-49) — never
        // overwrite (wipe) the stored refresh token with an empty/null value.
        $rotated = $tokens['refresh_token'] ?? null;
        if (is_string($rotated) && $rotated !== '') {
            $updates['refresh_token'] = encrypt($rotated);
        }

        $this->connection->update($updates);

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
