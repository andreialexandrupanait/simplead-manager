<?php

declare(strict_types=1);

namespace App\Backup\V2\Crypto;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a client's backup key comes from.
 *
 * One key per client, not one per site and not one for the whole platform. Per
 * site would mean a restore has to find the right key among hundreds; one global
 * key would mean a single compromise reaches every client's data. Per client
 * matches how the data is actually owned.
 *
 * The key is 32 random bytes held in an `encrypted` column, so at rest it is
 * sealed with APP_KEY and never appears in a query log, a database dump or a
 * backup of this application. That does make APP_KEY the root of the whole
 * scheme — which is the honest cost of encrypting on our side rather than
 * handing the job to the storage provider, and the reason `backup:export-keys`
 * exists.
 *
 * Generated on first use rather than in a migration: a client who has never had
 * a backup does not need a key, and creating one for them would put a secret in
 * the database that protects nothing.
 */
class BackupKeyring
{
    /** Key id written into objects for sites that belong to no client. */
    public const INSTALLATION_KEY_ID = 'install';

    public function forSite(Site $site): ?ObjectCipher
    {
        if (! (bool) config('backup_v2.encryption.enabled', true)) {
            return null;
        }

        /** @var Client|null $client */
        $client = $site->client;
        if (! $client instanceof Client) {
            // Three of this installation's sites have no client, and they are
            // real sites with real data. Refusing would ground them; writing
            // them in the clear would be a silent downgrade nobody asked for.
            // They get the installation key instead: the data is still
            // encrypted, it simply is not isolated to an owner, because there
            // is no owner to isolate it to.
            return $this->installationCipher();
        }

        return new ObjectCipher($this->keyFor($client), (string) $client->backup_key_id);
    }

    /**
     * The raw key for a client, generating one the first time it is needed.
     */
    public function keyFor(Client $client): string
    {
        if (blank($client->backup_key)) {
            $client->forceFill([
                'backup_key' => random_bytes(32),
                'backup_key_id' => 'ck_'.$client->id.'_'.Str::lower(Str::random(12)),
            ])->save();
            $client->refresh();
        }

        return (string) $client->backup_key;
    }

    /**
     * The fallback key, for sites that belong to no client.
     *
     * Derived from APP_KEY rather than stored, so there is nothing extra to
     * migrate, back up or leak — and no honesty lost, because APP_KEY is already
     * the root of the scheme: it is what seals every client key at rest. HKDF
     * with a fixed context string means this key is unrelated to anything else
     * APP_KEY protects, so exposing one does not expose the others.
     */
    public function installationCipher(): ObjectCipher
    {
        return new ObjectCipher($this->installationKey(), self::INSTALLATION_KEY_ID);
    }

    public function installationKey(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'simplead-backup-object-encryption');
    }

    /**
     * Resolve a cipher for an object written under a specific key id.
     *
     * Restore reads the key id out of the object's own header rather than
     * assuming the client's current key, so a backup taken before a key rotation
     * is still readable — and one taken under a key we no longer hold says so by
     * name instead of failing as generic corruption.
     */
    public function forKeyId(string $keyId): ObjectCipher
    {
        if ($keyId === self::INSTALLATION_KEY_ID) {
            return $this->installationCipher();
        }

        $client = Client::query()->where('backup_key_id', $keyId)->first();
        if (! $client instanceof Client || blank($client->backup_key)) {
            throw new RuntimeException(
                "No backup encryption key '{$keyId}' is held by this installation. "
                .'The backup cannot be decrypted without it; restore it from the key export.'
            );
        }

        return new ObjectCipher((string) $client->backup_key, $keyId);
    }
}
