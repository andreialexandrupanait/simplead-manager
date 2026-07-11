<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleConnection;
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
            $this->connection->update(['is_active' => false]);
            throw new \Exception('Google token encryption is invalid. Please reconnect your Google account in Settings > Integrations.');
        }

        $response = Http::timeout(10)->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            $this->connection->update(['is_active' => false]);
            throw new \Exception('Failed to refresh Google token');
        }

        $tokens = $response->json();

        $this->connection->update([
            'access_token' => encrypt($tokens['access_token']),
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        $this->accessToken = $tokens['access_token'];
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
