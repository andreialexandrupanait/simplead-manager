<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use App\Support\PluginPackage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Push the connector to one site and report what happened, in a form something other than a
 * console command can use.
 *
 * The logic existed twice already — in `connector:update` and in the queued push job — and the
 * fleet migration needs it a third time, as one step of three. A third copy would be the one that
 * drifts, so this is the shared answer to "make this site's connector current".
 */
class ConnectorPusher
{
    private const LINK_TTL_MINUTES = 30;

    public function __construct(
        private readonly WordPressApiServiceFactory $factory,
    ) {}

    /**
     * @return array{ok: bool, message: string, version?: string}
     */
    public function push(Site $site): array
    {
        $package = PluginPackage::connector();

        if (! $package->exists()) {
            return ['ok' => false, 'message' => 'The connector source is missing from this build.'];
        }

        $payload = [
            'download_url' => URL::temporarySignedRoute(
                'download.connector-plugin.signed',
                now()->addMinutes(self::LINK_TTL_MINUTES),
            ),
        ];

        // The hash is built from the same source the download controller serves, so the site can
        // prove it installed what we sent rather than whatever a cache had lying around.
        $hash = $package->hash();
        if ($hash !== null) {
            $payload['expected_hash'] = $hash;
        }

        try {
            $response = $this->factory->make($site)->request('POST', '/self-update', $payload, [], 180);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = (array) $response->json();

        if (! $response->successful()) {
            return ['ok' => false, 'message' => (string) ($body['error']['message'] ?? "HTTP {$response->status()}")];
        }

        $new = (string) ($body['new_version'] ?? 'unknown');
        if ($new !== '' && $new !== 'unknown') {
            $site->forceFill(['connector_version' => $new])->save();
        }

        return [
            'ok' => true,
            'message' => (string) ($body['old_version'] ?? '?').' -> '.$new,
            'version' => $new,
        ];
    }
}
