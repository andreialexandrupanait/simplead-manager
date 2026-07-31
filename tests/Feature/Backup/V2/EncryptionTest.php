<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Crypto\ObjectCipher;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * A backup holds the client's whole database and every file they uploaded.
 *
 * Written in the clear, anyone who can read the bucket has all of it — the
 * storage provider, anyone with a leaked access key, anyone who finds a
 * misconfigured policy. Server-side encryption would move the key to the
 * provider, which answers a different question.
 *
 * The framing matters as much as the cipher: a file chunk can be a hundred
 * megabytes, so one-shot GCM would need the whole object in memory on both
 * sides. Frames are sealed independently and bound to their position, so a
 * reordered or truncated file fails to open rather than decrypting to something
 * plausible.
 */
class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/enc-'.uniqid();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function cipher(string $keyId = 'ck_test_1'): ObjectCipher
    {
        return new ObjectCipher(str_repeat("\x2a", 32), $keyId);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    // ── round trip ───────────────────────────────────────────────────────

    public function test_a_file_survives_the_round_trip_byte_for_byte(): void
    {
        $original = $this->write('plain', random_bytes(64 * 1024));
        $cipher = $this->cipher();

        $cipher->encryptFile($original, $this->dir.'/sealed');
        $cipher->decryptFile($this->dir.'/sealed', $this->dir.'/back');

        $this->assertSame(
            hash_file('sha256', $original),
            hash_file('sha256', $this->dir.'/back'),
        );
    }

    /**
     * Crosses the 1 MiB frame boundary several times, which is where an
     * off-by-one in the framing would show up as a file that decrypts to
     * *almost* the right thing.
     */
    public function test_a_multi_frame_file_survives_intact(): void
    {
        $original = $this->write('big', random_bytes(3 * 1024 * 1024 + 7919));
        $cipher = $this->cipher();

        $cipher->encryptFile($original, $this->dir.'/sealed');
        $cipher->decryptFile($this->dir.'/sealed', $this->dir.'/back');

        $this->assertSame(filesize($original), filesize($this->dir.'/back'));
        $this->assertSame(hash_file('sha256', $original), hash_file('sha256', $this->dir.'/back'));
    }

    public function test_an_empty_file_round_trips(): void
    {
        $original = $this->write('empty', '');
        $cipher = $this->cipher();

        $cipher->encryptFile($original, $this->dir.'/sealed');
        $cipher->decryptFile($this->dir.'/sealed', $this->dir.'/back');

        $this->assertSame('', file_get_contents($this->dir.'/back'));
    }

    // ── what the storage provider sees ───────────────────────────────────

    public function test_the_stored_object_does_not_contain_the_plaintext(): void
    {
        $secret = 'wp_users:admin@example.com:$P$B0000000000000000000000000';
        $original = $this->write('db.sql', str_repeat($secret.PHP_EOL, 200));

        $this->cipher()->encryptFile($original, $this->dir.'/sealed');

        $stored = (string) file_get_contents($this->dir.'/sealed');
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString('wp_users', $stored);
    }

    // ── tampering ────────────────────────────────────────────────────────

    /**
     * The point of the whole scheme: altered bytes stop being restorable rather
     * than becoming a site restored from something that is not the backup.
     */
    public function test_a_flipped_bit_fails_authentication(): void
    {
        $original = $this->write('plain', random_bytes(4096));
        $this->cipher()->encryptFile($original, $this->dir.'/sealed');

        $bytes = (string) file_get_contents($this->dir.'/sealed');
        $bytes[strlen($bytes) - 10] = chr(ord($bytes[strlen($bytes) - 10]) ^ 0x01);
        file_put_contents($this->dir.'/sealed', $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        $this->cipher()->decryptFile($this->dir.'/sealed', $this->dir.'/back');
    }

    public function test_a_truncated_object_is_refused(): void
    {
        $original = $this->write('plain', random_bytes(2 * 1024 * 1024));
        $this->cipher()->encryptFile($original, $this->dir.'/sealed');

        $bytes = (string) file_get_contents($this->dir.'/sealed');
        file_put_contents($this->dir.'/sealed', substr($bytes, 0, (int) (strlen($bytes) * 0.6)));

        $this->expectException(RuntimeException::class);
        $this->cipher()->decryptFile($this->dir.'/sealed', $this->dir.'/back');
    }

    public function test_the_wrong_key_fails_by_name_rather_than_producing_rubbish(): void
    {
        $original = $this->write('plain', random_bytes(2048));
        $this->cipher('ck_a')->encryptFile($original, $this->dir.'/sealed');

        $other = new ObjectCipher(str_repeat("\x7f", 32), 'ck_b');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/encrypted with key 'ck_a', not 'ck_b'/");
        $other->decryptFile($this->dir.'/sealed', $this->dir.'/back');
    }

    // ── reading a backup back ────────────────────────────────────────────

    /**
     * Restore reads the key id from the object's own header rather than assuming
     * the client's current key, so a chain spanning a key rotation — or the day
     * encryption was switched on — still restores.
     */
    public function test_an_object_declares_which_key_sealed_it(): void
    {
        $original = $this->write('plain', 'hello');
        $this->cipher('ck_42_abc')->encryptFile($original, $this->dir.'/sealed');

        $this->assertTrue(ObjectCipher::isEncrypted($this->dir.'/sealed'));
        $this->assertSame('ck_42_abc', ObjectCipher::keyIdOf($this->dir.'/sealed'));
    }

    public function test_an_unencrypted_object_is_recognised_as_such(): void
    {
        $path = $this->write('legacy.zip', 'PK'.random_bytes(500));

        $this->assertFalse(ObjectCipher::isEncrypted($path));
        $this->assertNull(ObjectCipher::keyIdOf($path));
    }

    // ── the keyring ──────────────────────────────────────────────────────

    public function test_a_client_gets_one_key_generated_on_first_use(): void
    {
        $client = Client::factory()->create();
        $ring = new BackupKeyring;

        $first = $ring->keyFor($client);
        $second = $ring->keyFor($client->fresh());

        $this->assertSame(32, strlen($first));
        $this->assertSame($first, $second, 'the key must not change between backups');
        $this->assertNotNull($client->fresh()->backup_key_id);
    }

    public function test_two_clients_do_not_share_a_key(): void
    {
        $ring = new BackupKeyring;

        $this->assertNotSame(
            $ring->keyFor(Client::factory()->create()),
            $ring->keyFor(Client::factory()->create()),
        );
    }

    /**
     * A key we do not hold has to say so by name. Reported as generic corruption
     * it would send an operator looking for a damaged backup that is perfectly
     * intact.
     */
    public function test_an_unknown_key_id_is_named(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/No backup encryption key 'ck_missing'/");

        (new BackupKeyring)->forKeyId('ck_missing');
    }

    /**
     * Three of this installation's sites belong to no client, and they are real
     * sites with real data. Refusing would ground them; writing them in the
     * clear would be a silent downgrade. They get the installation key: still
     * encrypted, simply not isolated to an owner, because there is no owner.
     */
    public function test_a_site_with_no_client_uses_the_installation_key(): void
    {
        $site = Site::factory()->create(['client_id' => null]);
        $ring = new BackupKeyring;

        $cipher = $ring->forSite($site);
        $this->assertNotNull($cipher);

        $original = $this->write('plain', 'ownerless but not unprotected');
        $cipher->encryptFile($original, $this->dir.'/sealed');

        $this->assertStringNotContainsString('ownerless', (string) file_get_contents($this->dir.'/sealed'));
        $this->assertSame(BackupKeyring::INSTALLATION_KEY_ID, ObjectCipher::keyIdOf($this->dir.'/sealed'));

        // And it comes back, through the same resolution path a restore uses.
        $ring->forKeyId(BackupKeyring::INSTALLATION_KEY_ID)->decryptFile($this->dir.'/sealed', $this->dir.'/back');
        $this->assertSame('ownerless but not unprotected', file_get_contents($this->dir.'/back'));
    }

    public function test_encryption_can_be_switched_off_explicitly(): void
    {
        Config::set('backup_v2.encryption.enabled', false);

        $this->assertNull((new BackupKeyring)->forSite(Site::factory()->create()));
    }

    // ── escrow ───────────────────────────────────────────────────────────

    /**
     * Encrypting on our side buys one new way to lose everything: the keys are
     * sealed with APP_KEY, so a rebuilt installation with a different APP_KEY
     * cannot read a single object. This command is what makes that survivable.
     */
    public function test_the_keys_can_be_exported_for_safekeeping(): void
    {
        $client = Client::factory()->create();
        (new BackupKeyring)->keyFor($client);

        $this->artisan('backup:export-keys', ['--json' => true])
            ->expectsOutputToContain($client->fresh()->backup_key_id)
            ->assertSuccessful();
    }

    public function test_exporting_with_no_keys_yet_is_not_an_error(): void
    {
        $this->artisan('backup:export-keys')
            ->expectsOutputToContain('No backup encryption keys exist yet')
            ->assertSuccessful();
    }
}
