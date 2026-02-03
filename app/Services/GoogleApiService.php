<?php

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
        if ($this->connection->token_expires_at->isFuture()) {
            $this->accessToken = decrypt($this->connection->access_token);
            return;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => decrypt($this->connection->refresh_token),
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
        return Http::withToken($this->accessToken);
    }
}
