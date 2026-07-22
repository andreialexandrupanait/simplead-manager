<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * C-05: the trusted-proxies configuration in bootstrap/app.php runs during
 * application construction, before the config repository is bound. A previous
 * change (P3-34) swapped its env() source for config(), which fatals with
 * "Class 'config' does not exist" at that boot phase — every production request
 * 500'd. This guards the boot path: with proxy headers present, the app must
 * boot and respond, and the forwarded scheme/host must be honored.
 */
class TrustedProxiesTest extends TestCase
{
    public function test_app_boots_and_serves_a_request_carrying_forwarded_headers(): void
    {
        // The framework health endpoint (bootstrap/app.php health: '/up') exercises
        // the full boot + middleware stack without auth. A boot-time fatal in the
        // trustProxies closure would surface here as a 500, not a 200.
        $this->get('/up', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'manager.simplead.ro',
        ])->assertOk();
    }

    public function test_forwarded_proto_is_trusted_from_the_configured_proxy(): void
    {
        // With 127.0.0.1 trusted (the default in .env.example / prod), a request
        // whose forwarding proxy is the loopback and which declares
        // X-Forwarded-Proto: https must be seen as secure.
        config()->set('app.url', 'https://manager.simplead.ro');

        $secure = null;
        \Illuminate\Support\Facades\Route::get('/__proxy-probe', function (\Illuminate\Http\Request $request) use (&$secure) {
            $secure = $request->isSecure();

            return response('ok');
        })->middleware('web');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk();

        $this->assertTrue($secure, 'X-Forwarded-Proto: https from the trusted loopback proxy must be honored');
    }
}
