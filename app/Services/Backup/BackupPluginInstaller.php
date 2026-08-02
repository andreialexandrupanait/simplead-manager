<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use App\Support\PluginPackage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Puts the V2 backup engine on a site, through the connector.
 *
 * The engine had no distribution at all: it reached a site only if somebody downloaded the zip and
 * uploaded it by hand. One site out of twenty-four had it, and every fix to it — including the ones
 * that stop a restore filling a client's disk — stopped there. This is the missing half.
 *
 * The manager signs a short-lived download URL and tells the connector to fetch it, along with the
 * sha256 of the zip built from the same source, so the site can prove it installed what we sent
 * rather than whatever a cache or a proxy had lying around.
 */
class BackupPluginInstaller
{
    /**
     * How long the site has to fetch the package. Long enough for a slow host to download a few
     * hundred kilobytes, short enough that a URL captured in a log is worthless by the time anyone
     * reads it.
     */
    private const LINK_TTL_MINUTES = 30;

    public function __construct(
        private readonly WordPressApiServiceFactory $factory,
    ) {}

    /**
     * Ask the site which version of the engine it is running, and remember the answer.
     *
     * The fleet console needs this for every site at once. Asking twenty-nine WordPress
     * installations over HTTP on each page render is not a table — it is a minute of waiting and
     * twenty-nine requests to other people's servers — so the reading is stored, with the time it
     * was taken, and refreshed deliberately.
     */
    public function probe(Site $site): ?string
    {
        try {
            $caps = SimpleadBackupClient::forSite($site)->capabilities();
        } catch (Throwable) {
            // Not reachable, or the plugin is not there. Recorded as "unknown", not as a failure:
            // a site that has never had the plugin is the normal case before a migration.
            $this->remember($site, null);

            return null;
        }

        $version = (string) ($caps['plugin']['version'] ?? '');
        $this->remember($site, $version !== '' ? $version : null);

        return $version !== '' ? $version : null;
    }

    private function remember(Site $site, ?string $version): void
    {
        $site->forceFill([
            'backup_plugin_version' => $version,
            'backup_plugin_checked_at' => now(),
        ])->save();
    }

    /**
     * @return array{ok: bool, message: string, version?: string, installed?: bool}
     */
    public function install(Site $site): array
    {
        $package = PluginPackage::backupEngine();

        if (! $package->exists()) {
            return ['ok' => false, 'message' => 'The plugin source is missing from this build.'];
        }

        $payload = [
            'slug' => $package->slug,
            'download_url' => URL::temporarySignedRoute(
                'download.backup-plugin.signed',
                now()->addMinutes(self::LINK_TTL_MINUTES),
            ),
        ];

        $hash = $package->hash();
        if ($hash !== null) {
            $payload['expected_hash'] = $hash;
        }

        try {
            // Generous, because this is a download plus an unzip on someone else's shared host, and
            // the failure mode of being impatient here is a half-installed plugin.
            $response = $this->factory->make($site)->request('POST', '/plugins/install-package', $payload, [], 180);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = (array) $response->json();

        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $error = $body['error']['message'] ?? "HTTP {$response->status()}";

            // The one failure worth naming, because it is the one that will happen: this endpoint
            // arrived in connector 2.25.0, and a site on an older one answers 404 for a route it
            // has never heard of.
            if ($response->status() === 404) {
                $error = 'The connector on this site is too old to install plugins (needs 2.25.0 or newer). Push the connector first.';
            }

            return ['ok' => false, 'message' => (string) $error];
        }

        $version = (string) ($body['new_version'] ?? 'unknown');
        $installed = (bool) ($body['installed'] ?? false);

        $this->remember($site, $version);

        $message = $installed
            ? "installed {$version}"
            : trim((string) ($body['old_version'] ?? '?')).' -> '.$version;

        if (! ($body['active'] ?? true)) {
            $message .= ' (NOT ACTIVE: '.(string) ($body['activation_error'] ?? 'activation refused').')';
        }

        return ['ok' => true, 'message' => $message, 'version' => $version, 'installed' => $installed];
    }
}
