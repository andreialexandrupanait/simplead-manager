<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P2-55: decrypted channel configs (webhook URLs, tokens) must never be cached in
 * a shared store like Redis — anyone with cache access would read the plaintext.
 */
class NotificationChannelConfigCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_decrypted_config_is_not_left_in_the_cache_store(): void
    {
        $webhook = 'https://hooks.slack.com/services/T00000000/B11111111/secretTokenValue123456';

        $channel = NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => $webhook],
            'is_active' => true,
        ]);

        // Decryption still works on demand.
        $config = $channel->getDecryptedConfig();
        $this->assertSame($webhook, $config['webhook_url']);

        // The old cache key must hold no plaintext secret (it is no longer cached).
        $cached = Cache::get("notification_channel:{$channel->id}:config");
        $this->assertNull($cached);
    }
}
